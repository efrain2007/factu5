<?php

    namespace Modules\Suscription\Http\Requests;


    use Illuminate\Foundation\Http\FormRequest;
    use Illuminate\Validation\Rule;


    /**
     * Class PlanSuscriptionRequest
     *
     * @package Modules\Suscription\Http\Requests
     * @mixin FormRequest
     */
    class PaymentsSuscriptionRequest extends FormRequest
    {

        /**
         * @return bool
         */
        public function authorize()
        {
            return true;
        }

        /**
         * @return \string[][]
         */
        public function rules()
        {

            return [

                'suscription_plan_id' => [ 'required', ],
                'start_date' => [ 'required', ],
                'children_customer_id' => [ 'required', ],
                'parent_customer_id' => [ 'required', ],
                'parent_customer' => [ 'required', ],
                'children_customer' => [ 'required', ],

            ];
        }

        /**
         * @return array
         */
        public function messages()
        {
            return [
                'description.required' => 'El campo Descripción es obligatorio.',

                'suscription_plan_id.required' => 'El plan de suscripcion  es obligatorio.',
                'start_date.required' => 'La fecha de inicio es obligatoria.',
                'children_customer_id.required' => 'Se debe seleccionar un hijo',
                'parent_customer_id.required' => 'Se debe seleccionar un padre',
                'parent_customer.required' => 'El campo parent_customer es obligatorio.',
                'children_customer.required' => 'El campo children_customer es obligatorio.',
            ];
        }
    }
