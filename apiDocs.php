http://127.0.0.1:8000/api/v1/template-routes/2

Body Json
{
  "template": "Wistern Digital Template",
  "user_id": 3,
  "metadata": {
    "order_seq": 2,
    "route": "T02",
    "parameters": [
      { "label": "Material", "name": "material", "input": "text", "value": "Art Paper" },
      { "label": "Ink", "name": "ink", "input": "text", "value": "Water-based Ink" },
      { "label": "Interval", "name": "interval", "input": "text", "value": "1.5 sec" }
    ],
    "allow_user": [
      { "user_id": 3 },
      { "user_id": 4 }
    ]
  }
}


Batch insert work orders
POST http://127.0.0.1:8000/api/v1/work-orders/batch
Headers: Authorization: Bearer <token>, Content-Type: application/json

Body JSON
{
  "work_orders": [
    {
      "customer_id": 1,
      "template_route_id": 2,
      "work_order_no": "WB615487",
      "batch_number": "270125T1015",
      "selected": true,
      "mes_batch_no": "D271225T927",
      "customer_code": "P1283",
      "customer_name": "RESHEDA SIPET LTD",
      "material_1_code": "RPT351",
      "material_2_code": "L1551",
      "material_3_code": "XHX411",
      "material_4_code": "XHX412",
      "customer_part_number": "RSB4-RET/VC",
      "production_due_date": "2025-12-18",
      "quantity_to_produce": "432000",
      "quantity_produced": "0",
      "forecast_quantity": "0",
      "die_cut": "DC2558",
      "internal_remark": "",
      "requested_delivery_date": "2025-12-18",
      "no_of_colours": "4",
      "sales_person_code": "JT",
      "order_date": "2025-11-18",
      "production_date_completed": null,
      "production_qty_completed": "0",
      "qr_code": null,
      "metadata": {
        "notes": "Any custom JSON payload"
      }
    },
    {
      "customer_id": 1,
      "template_route_id": 2,
      "work_order_no": "WB615488",
      "selected": true,
      "mes_batch_no": "D271225T927",
      "customer_code": "P1283",
      "customer_name": "DECTRON DICKINSON MEDICAL (S) PTE LTD",
      "material_1_code": "RPT351",
      "customer_part_number": "H2562",
      "production_due_date": "2025-12-18",
      "quantity_to_produce": "432000",
      "die_cut": "DC2558",
      "internal_remark": "DYR : EUNIC / OAHIS",
      "requested_delivery_date": "2025-12-18",
      "no_of_colours": "4",
      "sales_person_code": "JT",
      "order_date": "2025-11-18"
    }
  ]
}

Notes:
- Provide at least one object inside `work_orders`.
- Omit `batch_number` to auto-generate the `ddmmyyTHHmm` sequence.
- All fields supported by the single create Work Order API can be supplied per item.


Batch logs CRUD
GET    http://127.0.0.1:8000/api/v1/batch-logs?batch_no=270125
POST   http://127.0.0.1:8000/api/v1/batch-logs
GET    http://127.0.0.1:8000/api/v1/batch-logs/{id}
PUT    http://127.0.0.1:8000/api/v1/batch-logs/{id}
DELETE http://127.0.0.1:8000/api/v1/batch-logs/{id}
(all require Authorization header)

Create/Update body JSON
{
  "user_id": 5,
  "batch_no": "270125T1015",
  "total_rows": 25,
  "operator": "jtan"
}

Filters:
- `batch_no` supports fuzzy search when passed either as `?batch_no=` or inside `filters[batch_no]`.
- `operator` filter works the same way.
- `user_id` can be supplied as `filters[user_id]` to show logs for a specific user; if `user_id` is omitted on create, the authenticated user's id is used automatically.
