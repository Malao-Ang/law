<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\LawSearchController;
use App\Services\Search\ElasticClient;
use App\Services\Search\LawSearchService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class LawTypeFilterAliasTest extends TestCase
{
    #[DataProvider('lawTypeFilterCases')]
    public function test_law_type_filter_key_accepts_names_codes_aliases_and_slugs(string $value, string $key, string $canonical): void
    {
        $controller = app(LawSearchController::class);
        $controllerKey = new ReflectionMethod($controller, 'lawTypeFilterKey');
        $controllerCanonical = new ReflectionMethod($controller, 'canonicalType');
        $controllerKey->setAccessible(true);
        $controllerCanonical->setAccessible(true);

        $service = new LawSearchService(\Mockery::mock(ElasticClient::class));
        $serviceKey = new ReflectionMethod($service, 'lawTypeFilterKey');
        $serviceKey->setAccessible(true);

        $this->assertSame($key, $controllerKey->invoke($controller, $value));
        $this->assertSame($key, $serviceKey->invoke($service, $value));
        $this->assertSame($canonical, $controllerCanonical->invoke($controller, $value));
    }

    public static function lawTypeFilterCases(): array
    {
        return [
            'family code ordinance' => ['LFM01', 'kho-bangkhab', 'kho-bangkhab'],
            'family slug ordinance' => ['kho-bangkhab', 'kho-bangkhab', 'kho-bangkhab'],
            'family slug regulation' => ['rabiap', 'rabiap', 'rabiap'],
            'family slug announcement' => ['prakat', 'prakat', 'prakat'],
            'family slug external' => ['kotmai-phaainok', 'external', 'kotmai-phaainok'],
            'family old external slug' => ['kotmai-krung', 'external', 'kotmai-phaainok'],
            'family external alias' => ['external', 'external', 'kotmai-phaainok'],
            'family external thai' => ["\u{0E01}\u{0E0E}\u{0E2B}\u{0E21}\u{0E32}\u{0E22}\u{0E20}\u{0E32}\u{0E22}\u{0E19}\u{0E2D}\u{0E01}", 'external', 'kotmai-phaainok'],
            'act code' => ['LTY05', 'external-act', 'kotmai-phaainok'],
            'act slug' => ['phrb', 'external-act', 'kotmai-phaainok'],
            'act old slug' => ['prb', 'external-act', 'kotmai-phaainok'],
            'act dotted' => ["\u{0E1E}.\u{0E23}.\u{0E1A}.", 'external-act', 'kotmai-phaainok'],
            'act dotted no tail' => ["\u{0E1E}.\u{0E23}.\u{0E1A}", 'external-act', 'kotmai-phaainok'],
            'act compact' => ["\u{0E1E}\u{0E23}\u{0E1A}", 'external-act', 'kotmai-phaainok'],
            'decree slug' => ['phrk', 'external-decree', 'kotmai-phaainok'],
            'decree dotted' => ["\u{0E1E}.\u{0E23}.\u{0E01}.", 'external-decree', 'kotmai-phaainok'],
            'decree compact' => ["\u{0E1E}\u{0E23}\u{0E01}", 'external-decree', 'kotmai-phaainok'],
            'ministerial rule slug' => ['kot-krathruang', 'external-ministerial-rule', 'kotmai-phaainok'],
            'ministerial rule old slug' => ['kotmai-krw', 'external-ministerial-rule', 'kotmai-phaainok'],
            'ministerial announcement slug' => ['prakat-krw', 'external-ministerial-announcement', 'kotmai-phaainok'],
            'command slug' => ['command', 'prakat', 'prakat'],
            'command thai' => ["\u{0E04}\u{0E33}\u{0E2A}\u{0E31}\u{0E48}\u{0E07}", 'prakat', 'prakat'],
            'resolution slug' => ['resolution', 'prakat', 'prakat'],
            'resolution thai' => ["\u{0E21}\u{0E15}\u{0E34}", 'prakat', 'prakat'],
        ];
    }
}
