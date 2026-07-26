<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LawSuggestRequest;
use App\Services\ReviewStore;
use App\Services\Search\LawSuggestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LawSuggestController extends Controller
{
    public function suggest(LawSuggestRequest $request, LawSuggestService $service, ReviewStore $store): JsonResponse
    {
        $params = $request->validated();

        try {
            $result = $service->suggest($params);
            if (($result['suggestions'] ?? []) !== []) {
                return response()->json($result);
            }
        } catch (\Throwable $exception) {
            Log::warning('Law suggest failed, falling back to file-based', ['error' => $exception->getMessage()]);
        }

        return response()->json($this->fileBasedSuggest($params, $store));
    }

    /** @param array<string,mixed> $params */
    private function fileBasedSuggest(array $params, ReviewStore $store): array
    {
        $query = mb_strtolower(trim((string) ($params['q'] ?? '')));
        $size = min(10, max(1, (int) ($params['size'] ?? 8)));

        if (mb_strlen($query) < 2) {
            return ['suggestions' => []];
        }

        $rows = [];
        foreach ($store->listLawMeta() as $row) {
            if (($row['status'] ?? '') !== 'ingested') {
                continue;
            }
            if (($row['access_scope'] ?? 'public') === 'private') {
                continue;
            }

            $score = $this->suggestScore($row, $query);
            if ($score <= 0) {
                continue;
            }

            $row['_suggest_score'] = $score;
            $rows[] = $row;
        }

        usort($rows, function (array $a, array $b) use ($query): int {
            $aTitle = (string) ($a['title'] ?? '');
            $bTitle = (string) ($b['title'] ?? '');
            $aStarts = mb_stripos($aTitle, $query) === 0;
            $bStarts = mb_stripos($bTitle, $query) === 0;

            return ((float) ($b['_suggest_score'] ?? 0.0) <=> (float) ($a['_suggest_score'] ?? 0.0))
                ?: ($bStarts <=> $aStarts)
                ?: strnatcasecmp($aTitle, $bTitle);
        });

        return [
            'suggestions' => array_map(
                fn (array $row): array => $this->suggestionFromMeta($row),
                array_slice($rows, 0, $size),
            ),
        ];
    }

    private function suggestScore(array $row, string $query): float
    {
        $best = 0.0;
        foreach ($this->suggestTextFields($row) as $text) {
            if (mb_stripos($text, $query) !== false) {
                $best = max($best, mb_stripos($text, $query) === 0 ? 1.0 : 0.92);
                continue;
            }

            $best = max($best, $this->textSimilarity($query, $text));
        }

        return $best >= 0.42 ? $best : 0.0;
    }

    /**
     * @return string[]
     */
    private function suggestTextFields(array $row): array
    {
        return array_values(array_filter([
            (string) ($row['title'] ?? ''),
            (string) ($row['law_type'] ?? ''),
            (string) ($row['meta_status'] ?? ''),
            (string) ($row['change_status'] ?? ''),
            (string) ($row['signer_group'] ?? ''),
            ...array_map('strval', (array) ($row['agencies'] ?? [])),
            ...array_map('strval', (array) ($row['law_groups'] ?? [])),
        ], static fn (string $value): bool => trim($value) !== ''));
    }

    private function suggestionFromMeta(array $row): array
    {
        $keywords = array_values(array_unique(array_filter([
            ...array_map('strval', (array) ($row['law_groups'] ?? [])),
            ...array_map('strval', (array) ($row['agencies'] ?? [])),
            (string) ($row['change_status'] ?? ''),
        ], static fn (string $value): bool => trim($value) !== '')));

        return [
            'law_id' => (string) ($row['document_id'] ?? ''),
            'title' => $row['title'] ?? null,
            'law_type' => $row['law_type'] ?? null,
            'agency' => $row['agencies'][0] ?? null,
            'published_date' => $row['promulgation_date'] ?? null,
            'keywords' => $keywords,
        ];
    }

    private function textSimilarity(string $query, string $candidate): float
    {
        $query = $this->normalizeSearchText($query);
        $candidate = $this->normalizeSearchText($candidate);
        if ($query === '' || $candidate === '') {
            return 0.0;
        }
        if (str_contains($candidate, $query)) {
            return 1.0;
        }

        $best = $this->diceSimilarity($query, $candidate);
        $queryLength = mb_strlen($query);
        $candidateLength = mb_strlen($candidate);
        $windowSize = min($candidateLength, $queryLength + 4);
        if ($windowSize >= 2 && $candidateLength > $windowSize) {
            for ($i = 0; $i <= $candidateLength - $windowSize; $i++) {
                $best = max($best, $this->diceSimilarity($query, mb_substr($candidate, $i, $windowSize)));
            }
        }

        return $best;
    }

    private function normalizeSearchText(string $text): string
    {
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($text)));
    }

    private function diceSimilarity(string $left, string $right): float
    {
        $leftGrams = $this->characterNgrams($left);
        $rightGrams = $this->characterNgrams($right);
        if ($leftGrams === [] || $rightGrams === []) {
            return $left === $right ? 1.0 : 0.0;
        }

        $rightCounts = array_count_values($rightGrams);
        $intersection = 0;
        foreach ($leftGrams as $gram) {
            if (($rightCounts[$gram] ?? 0) > 0) {
                $intersection++;
                $rightCounts[$gram]--;
            }
        }

        return (2 * $intersection) / (count($leftGrams) + count($rightGrams));
    }

    /**
     * @return string[]
     */
    private function characterNgrams(string $text): array
    {
        $length = mb_strlen($text);
        if ($length <= 1) {
            return $text === '' ? [] : [$text];
        }

        $grams = [];
        for ($i = 0; $i < $length - 1; $i++) {
            $grams[] = mb_substr($text, $i, 2);
        }

        return $grams;
    }
}
