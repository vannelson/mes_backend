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