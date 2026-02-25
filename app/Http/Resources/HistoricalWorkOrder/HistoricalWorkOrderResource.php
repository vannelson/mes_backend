<?php

namespace App\Http\Resources\HistoricalWorkOrder;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HistoricalWorkOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'work_order_no' => $this->work_order_no,
            'work_order_line_no' => $this->work_order_line_no,
            'wo_journal_line_no' => $this->wo_journal_line_no,
            'add_date' => $this->add_date,
            'add_user' => $this->add_user,
            'add_time' => $this->add_time,
            'material_batch_no' => $this->material_batch_no,
            'die_cut' => $this->die_cut,
            'process_no' => $this->process_no,
            'posted' => $this->posted,
            'machine_code' => $this->machine_code,
            'machine_name' => $this->machine_name,
            'machine_type' => $this->machine_type,
            'staff_code' => $this->staff_code,
            'staff_name' => $this->staff_name,
            'no_of_press' => $this->no_of_press,
            'date_started' => $this->date_started,
            'time_started' => $this->time_started,
            'date_completed' => $this->date_completed,
            'time_completed' => $this->time_completed,
            'no_of_ups' => $this->no_of_ups,
            'printed_quantity' => $this->printed_quantity,
            'journal_type' => $this->journal_type,
            'item_code' => $this->item_code,
            'quantity' => $this->quantity,
            'qc_approved_quantity' => $this->qc_approved_quantity,
            'rejected_quantity' => $this->rejected_quantity,
            'roll' => $this->roll,
            'length' => $this->length,
            'width' => $this->width,
            'length_uom' => $this->length_uom,
            'width_uom' => $this->width_uom,
            'rm_quantity' => $this->rm_quantity,
            'material_code' => $this->material_code,
            'variant_code' => $this->variant_code,
            'request_delivery_date' => $this->request_delivery_date,
            'non_qc_record' => $this->non_qc_record,
            'link_to_line_no' => $this->link_to_line_no,
            'related' => $this->related,
            'expire_date' => $this->expire_date,
            'label_remark' => $this->label_remark,
            'customer_part_number' => $this->customer_part_number,
            'customer_code' => $this->customer_code,
            'ddmm' => $this->ddmm,
            'label_packing_quantity_1' => $this->label_packing_quantity_1,
            'label_quantity_1' => $this->label_quantity_1,
            'label_packing_quantity_2' => $this->label_packing_quantity_2,
            'label_quantity_2' => $this->label_quantity_2,
            'label_packing_quantity_3' => $this->label_packing_quantity_3,
            'label_quantity_3' => $this->label_quantity_3,
            'uom_for_label_printing' => $this->uom_for_label_printing,
            'qc_inspector' => $this->qc_inspector,
            'summarised_period' => $this->summarised_period,
            'summarised' => $this->summarised,
            'posted_work_order_no' => $this->posted_work_order_no,
            'colour' => $this->colour,
            'currency' => $this->currency,
            'ref_customer' => $this->ref_customer,
            'group' => $this->group,
            'po' => $this->po,
            'source_doc_no' => $this->source_doc_no,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
