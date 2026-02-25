<?php
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$service = $app->make(App\Services\HistoricalWorkOrderService::class);
print_r($service->summary([]));
