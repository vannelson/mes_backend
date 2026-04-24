<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\Customer;
use App\Models\Diecut;
use App\Models\Machine;
use App\Models\TemplateRoute;
use App\Models\User;
use App\Models\WorkOrder;
use App\Traits\ResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class AiLabsController extends Controller
{
    use ResponseTrait;

    private const KEY_CACHE_PREFIX = 'ai_labs_openai_key:';

    private function cacheKey(int $userId): string
    {
        return self::KEY_CACHE_PREFIX . $userId;
    }

    private function resolveApiKey(int $userId): ?string
    {
        $encrypted = Cache::get($this->cacheKey($userId));
        if (!$encrypted) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable $e) {
            Cache::forget($this->cacheKey($userId));
            return null;
        }
    }

    public function status(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $hasKey = Cache::has($this->cacheKey($userId));

        return $this->success('AI key status.', [
            'has_key' => $hasKey,
        ]);
    }

    public function storeKey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => ['required', 'string', 'min:20'],
        ]);

        $userId = (int) $request->user()->id;
        $ttlMinutes = (int) config('ai_labs.key_ttl_minutes', 1440);
        $expiresAt = now()->addMinutes($ttlMinutes);

        Cache::put(
            $this->cacheKey($userId),
            Crypt::encryptString($validated['api_key']),
            $expiresAt
        );

        return $this->success('AI key saved.', [
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function clearKey(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;
        Cache::forget($this->cacheKey($userId));

        return $this->success('AI key cleared.');
    }

    private function streamSection(string $key, iterable $rows, int $total, callable $transform): void
    {
        $safeTotal = max(0, $total);

        echo '"' . $key . '":{';
        echo '"items":[';

        $first = true;
        foreach ($rows as $row) {
            $item = $transform($row);
            $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = 'null';
            }
            if (! $first) {
                echo ',';
            }
            echo $json;
            $first = false;
        }

        echo '],"total":' . $safeTotal . ',"meta":{"current_page":1,"last_page":1,"per_page":' . $safeTotal . ',"total":' . $safeTotal . '}}';
    }

    public function context(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        DB::disableQueryLog();

        $workOrdersTotal = WorkOrder::count();
        $templateRoutesTotal = TemplateRoute::count();
        $customersTotal = Customer::count();
        $bomsTotal = Bom::count();
        $diecutsTotal = Diecut::count();
        $machinesTotal = Machine::count();
        $personnelTotal = User::count();

        $workOrdersQuery = WorkOrder::query()
            ->leftJoin('template_routes', 'work_orders.template_route_id', '=', 'template_routes.id')
            ->select([
                'work_orders.id',
                'work_orders.customer_id',
                'work_orders.template_route_id',
                'work_orders.work_order_no',
                'work_orders.batch_number',
                'work_orders.mes_batch_no',
                'work_orders.customer_code',
                'work_orders.customer_name',
                'work_orders.customer_part_number',
                'work_orders.item_code',
                'work_orders.production_due_date',
                'work_orders.requested_delivery_date',
                'work_orders.quantity_to_produce',
                'work_orders.quantity_produced',
                'work_orders.forecast_quantity',
                'work_orders.die_cut',
                'work_orders.internal_remark',
                'work_orders.no_of_colours',
                'work_orders.sales_person_code',
                'work_orders.order_date',
                'work_orders.production_date_completed',
                'work_orders.production_qty_completed',
                'work_orders.status',
                'work_orders.metadata',
                'work_orders.created_at',
                DB::raw('template_routes.template as template_name'),
            ])
            ->orderBy('work_orders.id')
            ->lazy(1000);

        $templateRoutesQuery = TemplateRoute::query()
            ->select([
                'id',
                'uuid',
                'template',
                'wod_ref',
                'customer_part_number_ref',
                'batch_number',
                'sheet',
                'user_id',
                'metadata',
                'created_at',
            ])
            ->orderBy('id')
            ->lazy(1000);

        $customersQuery = Customer::query()
            ->select([
                'id',
                'customer_code',
                'customer_name',
                'status',
                'country',
                'city',
                'industry',
                'customer_group',
                'sales_representative',
                'payment_terms',
                'pricing_tier',
                'created_at',
            ])
            ->orderBy('id')
            ->lazy(1000);

        $bomsQuery = Bom::query()
            ->select([
                'id',
                'batch_number',
                'sheet',
                'customer_code',
                'part_no',
                'description',
                'material_1_code',
                'material_1_desc',
                'material_2_code',
                'material_2_desc',
                'material_3_code',
                'material_3_desc',
                'material_4_code',
                'material_4_desc',
                'colour_code_1',
                'colour_code_2',
                'colour_code_3',
                'colour_code_4',
                'created_at',
            ])
            ->orderBy('id')
            ->lazy(1000);

        $diecutsQuery = Diecut::query()
            ->select([
                'id',
                'batch_number',
                'sheet',
                'diecut_no',
                'diecut_type',
                'width',
                'length',
                'no_of_ups',
                'rev',
                'radius',
                'perforate',
                'int_ud',
                'created_at',
            ])
            ->orderBy('id')
            ->lazy(1000);

        $machinesQuery = Machine::query()
            ->select([
                'id',
                'production_area',
                'machine_name',
                'machine_type',
                'printing_type',
                'machine_no',
                'cost_center_new',
                'average_speed',
                'max_colors',
                'diecut_type',
                'metadata',
                'created_at',
            ])
            ->orderBy('id')
            ->lazy(1000);

        $personnelQuery = User::query()
            ->select([
                'id',
                'firstname',
                'lastname',
                'middlename',
                'position',
                'user_type',
                'email',
                'created_at',
            ])
            ->orderBy('id')
            ->lazy(1000);

        $updatedAt = now()->toIso8601String();

        return response()->stream(function () use (
            $updatedAt,
            $workOrdersQuery,
            $workOrdersTotal,
            $templateRoutesQuery,
            $templateRoutesTotal,
            $customersQuery,
            $customersTotal,
            $bomsQuery,
            $bomsTotal,
            $diecutsQuery,
            $diecutsTotal,
            $machinesQuery,
            $machinesTotal,
            $personnelQuery,
            $personnelTotal
        ): void {
            echo '{"status":true,"message":"AI Labs context retrieved successfully!","data":{';
            echo '"updatedAt":"' . $updatedAt . '",';

            $this->streamSection('workOrders', $workOrdersQuery, $workOrdersTotal, static function ($row): array {
                return [
                    'id' => $row->id,
                    'work_order_no' => $row->work_order_no,
                    'batch_number' => $row->batch_number,
                    'mes_batch_no' => $row->mes_batch_no,
                    'customer_id' => $row->customer_id,
                    'customer_code' => $row->customer_code,
                    'customer_name' => $row->customer_name,
                    'template_route_id' => $row->template_route_id,
                    'template_name' => $row->template_name ?? null,
                    'production_due_date' => $row->production_due_date,
                    'requested_delivery_date' => $row->requested_delivery_date,
                    'quantity_to_produce' => $row->quantity_to_produce,
                    'quantity_produced' => $row->quantity_produced,
                    'forecast_quantity' => $row->forecast_quantity,
                    'customer_part_number' => $row->customer_part_number,
                    'item_code' => $row->item_code,
                    'die_cut' => $row->die_cut,
                    'internal_remark' => $row->internal_remark,
                    'no_of_colours' => $row->no_of_colours,
                    'sales_person_code' => $row->sales_person_code,
                    'order_date' => $row->order_date,
                    'production_date_completed' => $row->production_date_completed,
                    'production_qty_completed' => $row->production_qty_completed,
                    'status' => $row->status,
                    'metadata' => $row->metadata,
                    'created_at' => $row->created_at,
                ];
            });

            echo ',';
            $this->streamSection('templateRoutes', $templateRoutesQuery, $templateRoutesTotal, static function ($row): array {
                return [
                    'id' => $row->id,
                    'uuid' => $row->uuid,
                    'template' => $row->template,
                    'wod_ref' => $row->wod_ref,
                    'customer_part_number_ref' => $row->customer_part_number_ref,
                    'batch_number' => $row->batch_number,
                    'sheet' => $row->sheet,
                    'user_id' => $row->user_id,
                    'metadata' => $row->metadata,
                    'created_at' => $row->created_at,
                ];
            });

            echo ',';
            $this->streamSection('customers', $customersQuery, $customersTotal, static function ($row): array {
                return [
                    'id' => $row->id,
                    'customer_code' => $row->customer_code,
                    'customer_name' => $row->customer_name,
                    'status' => $row->status,
                    'country' => $row->country,
                    'city' => $row->city,
                    'industry' => $row->industry,
                    'customer_group' => $row->customer_group,
                    'sales_representative' => $row->sales_representative,
                    'payment_terms' => $row->payment_terms,
                    'pricing_tier' => $row->pricing_tier,
                    'created_at' => $row->created_at,
                ];
            });

            echo ',';
            $this->streamSection('boms', $bomsQuery, $bomsTotal, static function ($row): array {
                return [
                    'id' => $row->id,
                    'batch_number' => $row->batch_number,
                    'sheet' => $row->sheet,
                    'customer_code' => $row->customer_code,
                    'part_no' => $row->part_no,
                    'description' => $row->description,
                    'material_1_code' => $row->material_1_code,
                    'material_1_desc' => $row->material_1_desc,
                    'material_2_code' => $row->material_2_code,
                    'material_2_desc' => $row->material_2_desc,
                    'material_3_code' => $row->material_3_code,
                    'material_3_desc' => $row->material_3_desc,
                    'material_4_code' => $row->material_4_code,
                    'material_4_desc' => $row->material_4_desc,
                    'colour_code_1' => $row->colour_code_1,
                    'colour_code_2' => $row->colour_code_2,
                    'colour_code_3' => $row->colour_code_3,
                    'colour_code_4' => $row->colour_code_4,
                    'created_at' => $row->created_at,
                ];
            });

            echo ',';
            $this->streamSection('diecuts', $diecutsQuery, $diecutsTotal, static function ($row): array {
                return [
                    'id' => $row->id,
                    'batch_number' => $row->batch_number,
                    'sheet' => $row->sheet,
                    'diecut_no' => $row->diecut_no,
                    'diecut_type' => $row->diecut_type,
                    'width' => $row->width,
                    'length' => $row->length,
                    'no_of_ups' => $row->no_of_ups,
                    'rev' => $row->rev,
                    'radius' => $row->radius,
                    'perforate' => $row->perforate,
                    'int_ud' => $row->int_ud,
                    'created_at' => $row->created_at,
                ];
            });

            echo ',';
            $this->streamSection('machines', $machinesQuery, $machinesTotal, static function ($row): array {
                return [
                    'id' => $row->id,
                    'production_area' => $row->production_area,
                    'machine_name' => $row->machine_name,
                    'machine_type' => $row->machine_type,
                    'printing_type' => $row->printing_type,
                    'machine_no' => $row->machine_no,
                    'cost_center_new' => $row->cost_center_new,
                    'average_speed' => $row->average_speed,
                    'max_colors' => $row->max_colors,
                    'diecut_type' => $row->diecut_type,
                    'metadata' => $row->metadata,
                    'created_at' => $row->created_at,
                ];
            });

            echo ',';
            $this->streamSection('personnel', $personnelQuery, $personnelTotal, static function ($row): array {
                return [
                    'id' => $row->id,
                    'firstname' => $row->firstname,
                    'lastname' => $row->lastname,
                    'middlename' => $row->middlename,
                    'position' => $row->position,
                    'user_type' => $row->user_type,
                    'email' => $row->email,
                    'created_at' => $row->created_at,
                ];
            });

            echo '}}';
        }, 200, ['Content-Type' => 'application/json']);
    }

    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'messages' => ['required', 'array', 'min:1'],
            'tools' => ['nullable', 'array'],
            'params' => ['nullable', 'array'],
            'params.model' => ['nullable', 'string'],
            'params.temperature' => ['nullable', 'numeric'],
            'params.max_tokens' => ['nullable', 'integer'],
        ]);

        $userId = (int) $request->user()->id;
        $apiKey = $this->resolveApiKey($userId);

        if (!$apiKey) {
            return $this->error('OpenAI API key not set. Please provide one.', 422);
        }

        $params = $validated['params'] ?? [];

        $payload = [
            'model' => $params['model'] ?? config('ai_labs.model', 'gpt-4o-mini'),
            'messages' => $validated['messages'],
            'temperature' => $params['temperature'] ?? 0.6,
            'max_tokens' => $params['max_tokens'] ?? 800,
        ];

        if (!empty($validated['tools'])) {
            $payload['tools'] = $validated['tools'];
        }

        $response = Http::withToken($apiKey)
            ->timeout(60)
            ->post('https://api.openai.com/v1/chat/completions', $payload);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? 'OpenAI request failed.';
            $status = $response->status();
            $httpStatus = in_array($status, [400, 429], true) ? $status : 422;

            return $this->error($message, $httpStatus);
        }

        return $this->success('AI response', $response->json());
    }
}
