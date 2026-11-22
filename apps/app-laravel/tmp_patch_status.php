<?php

use App\Services\MasterData\EnforcementStatuses;
use App\Services\ReviewStore;
use App\Services\Search\LawIndexer;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$store = app(ReviewStore::class);
$statuses = app(EnforcementStatuses::class);
$inForce = $statuses->codeForRole('in_force');
$updated = [];

echo "target_code={$inForce} label=".$statuses->labelOf($inForce).PHP_EOL;

foreach ($store->listDocuments() as $row) {
    $id = (string) ($row['document_id'] ?? '');
    if ($id === '') {
        continue;
    }

    $review = $store->getReviewDocument($id);
    $meta = is_array($review['law_meta'] ?? null) ? $review['law_meta'] : [];
    $before = (string) ($meta['status'] ?? '');
    if ($statuses->isInForce($before)) {
        echo "skip {$id} already=".$statuses->labelOf($before)." ({$before})".PHP_EOL;
        continue;
    }

    $store->patchLawMeta($id, ['status' => $inForce]);

    try {
        app(LawIndexer::class)->index($id);
    } catch (Throwable $e) {
        echo "warn index {$id}: ".$e->getMessage().PHP_EOL;
    }

    $fresh = $store->getReviewDocument($id);
    $after = (string) (($fresh['law_meta']['status'] ?? ''));
    $updated[] = $id;
    echo $id.' | '.($before === '' ? '(empty)' : $before.' / '.$statuses->labelOf($before))
        .' -> '.$after.' / '.$statuses->labelOf($after).PHP_EOL;
}

echo 'updated='.count($updated).PHP_EOL;
