<?php

namespace fostercommerce\meilisearch\services;

use Craft;
use craft\elements\db\ElementQuery;
use craft\elements\db\EntryQuery;
use craft\events\SearchEvent;
use fostercommerce\meilisearch\Plugin;

class CraftSearch extends \craft\services\Search
{
	private array $searchCache = [];

	/**
	 * When this returns true, Craft's ElementQuery::_applySearchParam() reads $elementQuery->orderBy['score'].
	 * That access is unsafe unless orderBy carries a 'score' key:
	 *  - An unordered query keeps orderBy as its default empty string '' (prepare()
	 *    skips normalizing empty values), so ''['score'] throws a fatal TypeError.
	 *  - An explicitly ordered query has orderBy as an array without a 'score' key, so orderBy['score'] is an
	 *    undefined-key access (a warning, promoted to an exception under dev mode).
	 * We therefore ensure a 'score' key exists: for an unordered search that means ranking by relevance;
	 * for an explicitly ordered search we append score as a tiebreaker, leaving the caller's ordering as the
	 * primary sort.
	 */
	public function shouldCallSearchElements(ElementQuery $elementQuery): bool
	{
		if ($this->getMeilisearchIndexHandle($elementQuery) !== null) {
			if (empty($elementQuery->orderBy)) {
				$elementQuery->orderBy = ['score' => SORT_DESC];
			} elseif (is_array($elementQuery->orderBy) && !isset($elementQuery->orderBy['score'])) {
				$elementQuery->orderBy['score'] = SORT_DESC;
			}

			return true;
		}

		return parent::shouldCallSearchElements($elementQuery);
	}

	public function searchElements(ElementQuery $elementQuery): array
	{
		$indexHandle = $this->getMeilisearchIndexHandle($elementQuery);

		if ($indexHandle === null) {
			return parent::searchElements($elementQuery);
		}

		$rawSearch = $elementQuery->search;
		$searchQuery = $this->normalizeSearchQuery($rawSearch);
		$cacheQuery = $this->createScopeQuery($elementQuery);
		$scopeKey = $this->scopeKey($cacheQuery);
		$searchKey = $this->searchCacheKey($scopeKey, $rawSearch);

		// A repeated query in this request reuses its scores; its search events ran on the first call.
		if ($searchKey !== null && isset($this->searchCache[$searchKey])) {
			return $this->searchCache[$searchKey];
		}

		$this->triggerBeforeSearch($elementQuery, $searchQuery);

		$validIds = $this->getScopeIds($cacheQuery, $scopeKey);
		$hits = $this->searchIndex($elementQuery, $indexHandle, $searchQuery->getQuery(), $validIds);
		$scores = $this->scoresForHits($hits, $elementQuery->siteId);
		$scores = $this->triggerAfterSearch($elementQuery, $searchQuery, $hits, $scores);

		// Cache search query
		if ($searchKey !== null) {
			$this->searchCache[$searchKey] = $scores;
		}

		return $scores;
	}

	// Check if queried section has a index in Meilisearch
	private function getMeilisearchIndexHandle(ElementQuery $elementQuery): ?string
	{
		if (!$elementQuery instanceof EntryQuery
			|| count((array)$elementQuery->sectionId) !== 1
			|| count((array)$elementQuery->siteId) !== 1
			|| empty(Plugin::getInstance()->getSettings()->getCraftSearch())
		) {
			return null;
		}

		$sectionIds = (array)$elementQuery->sectionId;
		$section = Craft::$app->getEntries()->getSectionById((int)reset($sectionIds));

		$siteIds = (array)$elementQuery->siteId;
		$site = Craft::$app->getSites()->getSiteById((int)reset($siteIds));

		if ($section === null || $site === null) {
			return null;
		}

		return Plugin::getInstance()->getSettings()->getCraftSearch($section->handle, $site->handle) ?? null;
	}

	// Remove search text and pagination so IDs cover the complete Craft query scope
	private function createScopeQuery(ElementQuery $elementQuery): ElementQuery
	{
		return (clone $elementQuery)
			->search(null)
			->offset(null)
			->limit(null);
	}

	// Include the raw search and scope in request memoization; skip console caching to avoid stale long-running results
	private function searchCacheKey(?string $scopeKey, mixed $rawSearch): ?string
	{
		if ($scopeKey === null || Craft::$app->getRequest()->getIsConsoleRequest()) {
			return null;
		}

		return $scopeKey . ':' . md5(serialize($rawSearch));
	}

	private function triggerBeforeSearch(ElementQuery $elementQuery, mixed $searchQuery): void
	{
		if (!$this->hasEventHandlers(self::EVENT_BEFORE_SEARCH)) {
			return;
		}

		$this->trigger(self::EVENT_BEFORE_SEARCH, new SearchEvent([
			'elementQuery' => $elementQuery,
			'query' => $searchQuery,
			'siteId' => $elementQuery->siteId,
		]));
	}

	// Fetch Craft's eligible IDs, caching them only for public site requests when configured.
	private function getScopeIds(ElementQuery $cacheQuery, ?string $scopeKey): array
	{
		$duration = Plugin::getInstance()->getSettings()->scopeCacheDuration;
		if ($scopeKey === null || $duration <= 0 || !$this->isScopeCacheableRequest()) {
			return $cacheQuery->ids();
		}

		return Craft::$app->getCache()->getOrSet(
			'meilisearch:scope:' . $scopeKey,
			fn() => $cacheQuery->ids(),
			$duration
		);
	}

	private function searchIndex(ElementQuery $elementQuery, string $indexHandle, string $query, array $validIds): array
	{
		$result = Plugin::getInstance()->search->search(
			$indexHandle,
			$query,
			[
				'offset' => $elementQuery->offset ?? 0,
				'limit' => $elementQuery->limit ?? PHP_INT_MAX,
				'filter' => 'id IN ' . json_encode($validIds),
			]
		);

		return $result->getHits();
	}

	private function scoresForHits(array $hits, mixed $siteId): array
	{
		$scores = [];
		$hitCount = count($hits);

		foreach ($hits as $position => $hit) {
			if (isset($hit['id'])) {
				$scores[sprintf('%s-%s', $hit['id'], $siteId)] = $hitCount - $position;
			}
		}

		return $scores;
	}

	private function triggerAfterSearch(ElementQuery $elementQuery, mixed $searchQuery, array $hits, array $scores): array
	{
		if (!$this->hasEventHandlers(self::EVENT_AFTER_SEARCH)) {
			return $scores;
		}

		$event = new SearchEvent([
			'elementQuery' => $elementQuery,
			'query' => $searchQuery,
			'siteId' => $elementQuery->siteId,
			'results' => $hits,
			'scores' => $scores,
		]);
		$this->trigger(self::EVENT_AFTER_SEARCH, $event);

		// Event handlers can replace scores before Craft uses them to order matching entries.
		return $event->scores ?? $scores;
	}

	// Scope IDs are shared only for public site requests, where previews and editor changes cannot contaminate cached results.
	private function isScopeCacheableRequest(): bool
	{
		$request = Craft::$app->getRequest();
		return !$request->getIsConsoleRequest() && $request->getIsSiteRequest() && !$request->getIsPreview();
	}

	// Build a key from query type, criteria, and site; return null when criteria cannot be serialized safely.
	private function scopeKey(ElementQuery $elementQuery): ?string
	{
		try {
			return md5(get_class($elementQuery) . '|' . serialize($elementQuery->getCriteria()) . '|' . serialize($elementQuery->siteId));
		} catch (\Throwable) {
			return null;
		}
	}
}
