<?php

    namespace App\Http\Controllers;

    use App\Models\Tenant\Configuration;
    use App\Models\Tenant\Item;
    use App\Models\Tenant\Warehouse;
    use Illuminate\Database\Query\Builder;
    use Illuminate\Http\Request;
    use Illuminate\Support\Collection;

    /**
     * Tener en cuenta como base modules/Document/Traits/SearchTrait.php
     * Class SearchItemController
     *
     * @package App\Http\Controllers
     * @mixin Controller
     */
    class SearchItemController extends Controller
    {


        /**
         * Devuelve una lista de items unido entre service y no service.
         *
         * @param Request|null $request
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function getAllItem(Request $request = null)
        {

            $establishment_id = auth()->user()->establishment_id;
            $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();

            self::validateRequest($request);
            $notService = self::getNotServiceItem($request);
            $Service = self::getServiceItem($request);
            $notService->merge($Service);
            return $notService->transform(function ($row) use ($warehouse) {
                /** @var Item $row */

                return $row->getDataToItemModal($warehouse);
            });
        }

        /**
         * @param Request|null $request
         */
        protected static function validateRequest(&$request)
        {
            if ($request == null) $request = new Request();

        }

        /**
         * @param Request|null $request
         * @param int          $id
         *
         * @return \Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection
         */
        public static function getNotServiceItem(Request $request = null, $id = 0)
        {

            self::validateRequest($request);
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;
            $input = self::setInputByRequest($request);
            $item = self::getAllItemBase($request, false, $id);

            if ($search_by_barcode === false && $input != null) {
                self::SetWarehouseToUser($item);
            }


            return $item->orderBy('description')->get();
        }

        /**
         * Busca la propiedad input o input_item para generar busquedas
         *
         * @param Request|null $request
         *
         * @return mixed|null
         */
        protected static function setInputByRequest(Request $request = null)
        {
            if (!empty($request)) {
                $input = ($request->has('input')) ? $request->input : null;
                if (empty($input) && $request->has('input_item')) {
                    $input = ($request->has('input_item')) ? $request->input_item : null;
                }
            }
            return $input;
        }

        /**
         * @param Request|null $request
         *
         * @return \Illuminate\Database\Eloquent\Builder
         */
        public static function getAllItemBase(Request $request = null, $service = false, $id = 0)
        {

            self::validateRequest($request);
            $search_item_by_series = Configuration::first()->isSearchItemBySeries();

            $items_id = ($request->has('items_id')) ? $request->items_id : null;
            $id = (int)$id;
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;


            $input = self::setInputByRequest($request);

            $item = Item:: whereIsActive()//    ->whereTypeUser()
            ;
            $ItemToSearchBySeries = Item:: whereIsActive();
            if ($service == false) {
                $item->WhereNotService()
                    ->with('warehousePrices');
                $ItemToSearchBySeries->WhereNotService()
                    ->with('warehousePrices');
            } else {
                $item
                    ->WhereService()
                    // ->with(['item_lots'])
                    ->whereNotIsSet();
                $ItemToSearchBySeries
                    ->WhereService()
                    // ->with(['item_lots'])
                    ->whereNotIsSet()
                ;


            }

            $alt_item = $item;

            $bySerie = null;
            if ($search_item_by_series == true) {
                self::validateRequest($request);
                $warehouse = Warehouse::select('id')->where('establishment_id', auth()->user()->establishment_id)->first();
                $input = self::setInputByRequest($request);
                if (!empty($input)) {

                    $ItemToSearchBySeries->WhereHas('item_lots', function ($query) use ($warehouse, $input) {
                        $query->where('has_sale', false);
                        $query->where('warehouse_id', $warehouse->id);
                        $query->where('series', $input);
                        // return $query;
                    })->take(1);

                    //Busca el item con relacion al almacen
                    self::SetWarehouseToUser($item);
                    self::SetWarehouseToUser($ItemToSearchBySeries);
                    $bySerie = $ItemToSearchBySeries->first();
                    if ($bySerie !== null) {
                        //Si existe un dato, devuelve la busqueda por serie.
                        $item->WhereHas('item_lots', function ($query) use ($warehouse, $input) {
                            $query->where('has_sale', false);
                            $query->where('warehouse_id', $warehouse->id);
                            $query->where('series', $input);
                        })->take(1);


                    }
                }
            }
            if ($bySerie === null) {
                if ($items_id != null) {
                    $item->whereIn('id', $items_id);
                } elseif ($id != 0) {
                    $item->where('id', $id);
                } else {


                    if ($search_by_barcode === true) {
                        $item
                            ->where('barcode', $input)
                            ->limit(1);
                    } else {
                        self::setFilter($item, $request);
                        $item->take(20);
                    }
                }
            }

            return $item->orderBy('description');
        }

        /**
         * Establece que solo se mostraria los item donde el usuario se encuentra
         *
         * @param $item
         */
        public static function SetWarehouseToUser(&$item)
        {
            /** @var Item $item */
            // $item->whereWarehouse();

        }

        /**
         * @param              $item
         * @param Request|null $request
         */
        protected static function setFilter(&$item, Request $request = null)
        {

            $input = self::setInputByRequest($request);

            if (!empty($input)) {
                $whereItem[] = ['description', 'like', '%' . $input . '%'];
                $whereItem[] = ['internal_id', 'like', '%' . $input . '%'];
                $whereItem[] = ['barcode', '=', $input];
                $whereExtra[] = ['name', 'like', '%' . $input . '%'];

                foreach ($whereItem as $index => $wItem) {
                    if ($index < 1) {
                        $item->Where([$wItem]);
                    } else {
                        $item->orWhere([$wItem]);
                    }
                }

                if (!empty($whereExtra)) {
                    $item
                        ->orWhereHas('brand', function ($query) use ($whereExtra) {
                            $query->where($whereExtra);
                        })
                        ->orWhereHas('category', function ($query) use ($whereExtra) {
                            $query->where($whereExtra);
                        });
                }
                $item->OrWhereJsonContains('attributes', ['value' => $input]);
                /** @var Builder $item */
            }


        }

        /**
         * @param Request|null $request
         * @param int          $id
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function getServiceItem(Request $request = null, $id = 0)
        {
            self::validateRequest($request);
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;
            $input = self::setInputByRequest($request);
            /** @var Item $item */
            $item = self::getAllItemBase($request, true, $id);

            if ($search_by_barcode === false && $input != null) {
                self::SetWarehouseToUser($item);
            }


            return $item->orderBy('description')->get();

        }

        /**
         * @param Request|null $request
         *
         * @return \Illuminate\Database\Eloquent\Collection|Collection
         */
        public static function getNotServiceItemToModal(Request $request = null, $id = 0)
        {
            $establishment_id = auth()->user()->establishment_id;
            $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();
            self::validateRequest($request);
            return self::getNotServiceItem($request, $id)->transform(function ($row) use ($warehouse) {
                /** @var Item $row */

                return $row->getDataToItemModal($warehouse);
            });
        }

        /**
         * Reaqliza una busqueda de item por id, Intenta por item, luego por servicio
         * Devuelve un standar de modal
         *
         * @param int $id
         *
         * @return \Illuminate\Database\Eloquent\Collection|Collection
         */
        public static function searchByIdToModal($id = 0)
        {
            $establishment_id = auth()->user()->establishment_id;
            $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();

            $items = self::searchById($id)->transform(function ($row) use ($warehouse) {
                /** @var Item $row */
                return $row->getDataToItemModal(
                    $warehouse,
                    true,
                    null,
                    false,
                    true
                );

            });
            return $items;
        }

        /**
         * @param int $id
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function searchById($id = 0)
        {
            $search_item = self::getNotServiceItem(null, $id);
            if (count($search_item) == 0) {
                $search_item = self::getServiceItem(null, $id);

            }
            return $search_item;
        }

        /**
         * @param Request $request
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function searchByRequest(Request $request)
        {
            $search_item = self::getNotServiceItem($request);
            if (count($search_item) == 0) {
                $search_item = self::getServiceItem($request);

            }
            return $search_item;
        }

        /**
         * @param int $id
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function searchByIdToPurchase($id = 0)
        {
            $search_item = self::getNotServiceItemToPurchase(null, $id);
            if (count($search_item) == 0) {
                $search_item = self::getServiceItemToPurchase(null, $id);

            }
            return $search_item;
        }

        /**
         * Devuelve el conjunto para ventas sin los pack o productos compuestos
         *
         * @param Request|null $request
         * @param int          $id
         *
         * @return \Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection
         */
        public static function getNotServiceItemToPurchase(Request $request = null, $id = 0)
        {

            self::validateRequest($request);
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;
            $input = self::setInputByRequest($request);

            $item = self::getAllItemBase($request, false, $id);

            $item->WhereNotIsSet();


            if ($search_by_barcode === false && $input != null) {
                self::SetWarehouseToUser($item);
            }


            return $item->orderBy('description')->get();
        }

        /**
         * @param Request|null $request
         * @param int          $id
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function getServiceItemToPurchase(Request $request = null, $id = 0)
        {
            self::validateRequest($request);
            $search_by_barcode = $request->has('search_by_barcode') && (bool)$request->search_by_barcode;
            $input = self::setInputByRequest($request);
            /** @var Item $item */
            $item = self::getAllItemBase($request, true, $id);
            $item->WhereNotIsSet();

            if ($search_by_barcode === false && $input != null) {
                self::SetWarehouseToUser($item);
            }


            return $item->orderBy('description')->get();

        }

        /**
         * @param Request $request
         *
         * @return Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed
         */
        public static function searchByRequestToPurchase(Request $request)
        {
            $search_item = self::getNotServiceItemToPurchase($request);
            if (count($search_item) == 0) {
                $search_item = self::getServiceItemToPurchase($request);

            }
            return $search_item;
        }


        /**
         * Retorna la coleccion de items par Documento y Boleta.
         *  Usado en app/Http/Controllers/Tenant/DocumentController.php::250
         *  Usado en app/Http/Controllers/Tenant/DocumentController.php::370
         *  Usado en modules/Document/Http/Controllers/DocumentController.php::297
         *
         * @param Request| null $request
         * @param int     $id
         *
         * @return \Illuminate\Database\Eloquent\Collection|Collection
         */
        public static function getItemsToDocuments(Request $request = null,$id = 0)
        {
            $items_not_services = self::getNotServiceItem($request, $id);
            $items_services = self::getServiceItem($request, $id);
            return self::TransformToModal($items_not_services->merge($items_services));
            $establishment_id = auth()->user()->establishment_id;
            $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();
            // $items_u = Item::whereWarehouse()->whereIsActive()->whereNotIsSet()->orderBy('description')->take(20)->get();
            $item_not_service = Item::with('warehousePrices')
                ->whereIsActive()
                ->orderBy('description');
            $service_item = Item::with('warehousePrices')
                ->where('items.unit_type_id', 'ZZ')
                ->whereIsActive()
                ->orderBy('description');
            $item_not_service = $item_not_service->take(20)->get();
            $service_item = $service_item->take(10)->get();
            return self::TransformToModal($item_not_service->merge($service_item));
        }

        /**
         * @param Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed $items
         * @param Warehouse|null                                                                                                     $warehouse
         *
         * @return \Illuminate\Database\Eloquent\Collection|Collection
         */
        public static function TransformToModal($items, Warehouse $warehouse = null)
        {
            /** @var Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed $items */
            return $items
                ->transform(function ($row) use ($warehouse) {
                    /** @var Item $row */
                    return $row->getDataToItemModal($warehouse);
                });

        }

        /**
         * @param Request|null $request
         * @param int          $id
         *
         * @return \Illuminate\Database\Eloquent\Collection
         */
        public static function getItemsToSaleNote(Request $request = null, $id = 0)
        {

            /*

            $items_u = Item::whereWarehouse()->whereIsActive()->whereNotIsSet()->orderBy('description')->take(20)->get();

            $items_s = Item::where('unit_type_id','ZZ')->whereIsActive()->orderBy('description')->take(10)->get();

            $items = $items_u->merge($items_s);
            */


            $establishment_id = auth()->user()->establishment_id;
            $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();

            $items_not_services = self::getNotServiceItem($request, $id);
            $items_services = self::getServiceItem($request, $id);

            return self::TransformToModalSaleNote($items_not_services->merge($items_services),$warehouse);

        }

        /**
         * @param Item[]|\Illuminate\Database\Eloquent\Builder[]|\Illuminate\Database\Eloquent\Collection|Builder[]|Collection|mixed $items
         * @param Warehouse|null                                                                                                     $warehouse
         *
         * @return \Illuminate\Database\Eloquent\Collection|Collection
         */
        public static function TransformToModalSaleNote($items, Warehouse $warehouse = null)
        {
            $warehouse_id = ($warehouse) ? $warehouse->id : null;
            if($warehouse_id == null){
                $establishment_id = auth()->user()->establishment_id;
                $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();
                $warehouse_id = ($warehouse) ? $warehouse->id : null;
            }

            return $items->transform(function ($row) use ($warehouse_id, $warehouse) {
                /** @var Item $row */
                            $detail = $row->getFullDescription($warehouse, false);

                return [
                    'id' => $row->id,
                    'full_description' => $detail['full_description'],
                    'brand' => $detail['brand'],
                    'category' => $detail['category'],
                    'stock' => $detail['stock'],
                    'description' => $row->description,
                    'currency_type_id' => $row->currency_type_id,
                    'currency_type_symbol' => $row->currency_type->symbol,
                    'sale_unit_price' => round($row->sale_unit_price, 2),
                    'purchase_unit_price' => $row->purchase_unit_price,
                    'unit_type_id' => $row->unit_type_id,
                    'sale_affectation_igv_type_id' => $row->sale_affectation_igv_type_id,
                    'purchase_affectation_igv_type_id' => $row->purchase_affectation_igv_type_id,
                    'has_igv' => (bool)$row->has_igv,
                    'lots_enabled' => (bool)$row->lots_enabled,
                    'series_enabled' => (bool)$row->series_enabled,
                    'is_set' => (bool)$row->is_set,
                    'warehouses' => collect($row->warehouses)->transform(function ($row) use ($warehouse_id) {
                        /** @var \App\Models\Tenant\ItemWarehouse  $row */
                        /** @var \App\Models\Tenant\Warehouse $c_warehouse */
                        $c_warehouse = $row->warehouse;

                        return [
                            'warehouse_id' => $c_warehouse->id,
                            'warehouse_description' => $c_warehouse->description,
                            'stock' => $row->stock,
                            'checked' => ($c_warehouse->id ==$warehouse_id) ? true : false,
                        ];
                    }),
                    'item_unit_types' => $row->item_unit_types,
                    'lots' => [],
                    'lots_group' => collect($row->lots_group)->transform(function ($row) {
                        return [
                            'id' => $row->id,
                            'code' => $row->code,
                            'quantity' => $row->quantity,
                            'date_of_due' => $row->date_of_due,
                            'checked' => false
                        ];
                    }),
                    'lot_code' => $row->lot_code,
                    'date_of_due' => $row->date_of_due
                ];
            });

        }

        /**
         * @param Item           $item
         * @param Warehouse|null $warehouse
         *
         * @return string[]
         */
        public static function getFullDescriptionToSaleNote(Item $item, Warehouse $warehouse = null)
        {

            $desc = ($item->internal_id) ? $item->internal_id . ' - ' . $item->description : $item->description;
            $category = ($item->category) ? "{$item->category->name}" : "";
            $brand = ($item->brand) ? "{$item->brand->name}" : "";

            if ($item->unit_type_id != 'ZZ') {
                $warehouse_stock = ($item->warehouses && $warehouse) ? number_format($item->warehouses->where('warehouse_id', $warehouse->id)->first() != null ? $item->warehouses->where('warehouse_id', $warehouse->id)->first()->stock : 0, 2) : 0;
                $stock = ($item->warehouses && $warehouse) ? "{$warehouse_stock}" : "";
            } else {
                $stock = '';
            }


            $desc = "{$desc} - {$brand}";

            return [
                'full_description' => $desc,
                'brand' => $brand,
                'category' => $category,
                'stock' => $stock,
            ];
        }

        /**
         * @return \Illuminate\Database\Eloquent\Collection|Collection
         */
        public static function getItemsToQuotation()
        {
            $items = Item::orderBy('description')
                ->whereIsActive()
                // ->with(['warehouses' => function($query) use($warehouse){
                //     return $query->where('warehouse_id', $warehouse->id);
                // }])
                ->take(20)->get();
            return self::TransformToModal($items);

        }

        /**
         * @param Request|null $request
         * @param int          $id
         *
         * @return mixed
         */
        public static function getItemsToOrderNote(Request $request = null, $id = 0)
        {
            $items_not_services = self::getNotServiceItem($request, $id);
            $items_services = self::getServiceItem($request, $id);
            $establishment_id = auth()->user()->establishment_id;
            $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();

            return self::TransformModalToOrderNote($items_not_services->merge($items_services), $warehouse);
        }

        /**
         * @param                $items
         * @param Warehouse|null $warehouse
         *
         * @return mixed
         */
        public static function TransformModalToOrderNote($items, Warehouse $warehouse = null)
        {
            $warehouse_id = ($warehouse) ? $warehouse->id : null;

            if($warehouse_id == null){
                $establishment_id = auth()->user()->establishment_id;
                $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();
                $warehouse_id = ($warehouse) ? $warehouse->id : null;
            }
            return $items->transform(function ($row) use ($warehouse_id, $warehouse) {
                /** @var Item $row */
                $detail = self::getFullDescriptionToSaleNote($row, $warehouse);
                return [
                    'id' => $row->id,
                    'full_description' => $detail['full_description'],
                    'brand' => $detail['brand'],
                    'category' => $detail['category'],
                    'stock' => $detail['stock'],
                    'description' => $row->description,
                    'currency_type_id' => $row->currency_type_id,
                    'currency_type_symbol' => $row->currency_type->symbol,
                    'sale_unit_price' => round($row->sale_unit_price, 2),
                    'purchase_unit_price' => $row->purchase_unit_price,
                    'unit_type_id' => $row->unit_type_id,
                    'sale_affectation_igv_type_id' => $row->sale_affectation_igv_type_id,
                    'purchase_affectation_igv_type_id' => $row->purchase_affectation_igv_type_id,
                    'has_igv' => (bool)$row->has_igv,
                    'lots_enabled' => (bool)$row->lots_enabled,
                    'series_enabled' => (bool)$row->series_enabled,
                    'is_set' => (bool)$row->is_set,
                    'warehouses' => collect($row->warehouses)->transform(function ($row) use ($warehouse) {
                        return [
                            'warehouse_id' => $row->warehouse->id,
                            'warehouse_description' => $row->warehouse->description,
                            'stock' => $row->stock,
                            'checked' => ($row->warehouse_id == $warehouse->id) ? true : false,
                        ];
                    }),
                    'item_unit_types' => $row->item_unit_types,
                    'lots' => [],
                    'lots_group' => collect($row->lots_group)->transform(function ($row) {
                        return [
                            'id' => $row->id,
                            'code' => $row->code,
                            'quantity' => $row->quantity,
                            'date_of_due' => $row->date_of_due,
                            'checked' => false
                        ];
                    }),
                    'lot_code' => $row->lot_code,
                    'date_of_due' => $row->date_of_due
                ];
            });
        }

        /**
         * @param Request|null $request
         * @param int          $id
         *
         * @return mixed
         */
        public static function getItemToPurchaseOrder(Request $request = null, $id = 0)
        {
            $items_not_services = self::getNotServiceItem($request, $id);
            $items_services = self::getServiceItem($request, $id);
            $establishment_id = auth()->user()->establishment_id;
            $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();

            return self::TransformModalToPurchaseOrder($items_not_services->merge($items_services), $warehouse);
            //
        }

        /**
         * @param                $items
         * @param Warehouse|null $warehouse
         *
         * @return mixed
         */
        public static function TransformModalToPurchaseOrder($items, Warehouse $warehouse = null)
        {
            $warehouse_id = ($warehouse) ? $warehouse->id : null;

            if($warehouse_id == null){
                $establishment_id = auth()->user()->establishment_id;
                $warehouse = Warehouse::where('establishment_id', $establishment_id)->first();
                $warehouse_id = ($warehouse) ? $warehouse->id : null;
            }
            return $items->transform(function ($row) use ($warehouse_id, $warehouse) {
                /** @var Item $row */
                $full_description = self::getFullDescriptionToPurchaseOrder($row);
                return [
                    'id' => $row->id,
                    'full_description' => $full_description,
                    'description' => $row->description,
                    'model' => $row->model,
                    'currency_type_id' => $row->currency_type_id,
                    'currency_type_symbol' => $row->currency_type->symbol,
                    'sale_unit_price' => $row->sale_unit_price,
                    'purchase_unit_price' => $row->purchase_unit_price,
                    'unit_type_id' => $row->unit_type_id,
                    'sale_affectation_igv_type_id' => $row->sale_affectation_igv_type_id,
                    'purchase_affectation_igv_type_id' => $row->purchase_affectation_igv_type_id,
                    'has_perception' => (bool)$row->has_perception,
                    'purchase_has_igv' => (bool)$row->purchase_has_igv,
                    'percentage_perception' => $row->percentage_perception,
                    'item_unit_types' => collect($row->item_unit_types)->transform(function ($row) {
                        return [
                            'id' => $row->id,
                            'description' => "{$row->description}",
                            'item_id' => $row->item_id,
                            'unit_type_id' => $row->unit_type_id,
                            'quantity_unit' => $row->quantity_unit,
                            'price1' => $row->price1,
                            'price2' => $row->price2,
                            'price3' => $row->price3,
                            'price_default' => $row->price_default,
                        ];
                    }),
                    'series_enabled' => (bool)$row->series_enabled,
                ];
            });
        }

        /**
         * @param Item $item
         *
         * @return string
         */
        public static function getFullDescriptionToPurchaseOrder(Item $item)
        {

            $desc = ($item->internal_id) ? $item->internal_id . ' - ' . $item->description : $item->description;
            $category = ($item->category) ? " - {$item->category->name}" : "";
            $brand = ($item->brand) ? " - {$item->brand->name}" : "";

            $desc = "{$desc} {$category} {$brand}";

            return $desc;
        }

        /**
         * @param Request $request
         * @param int     $id
         *
         * @return mixed
         */
        public static function getItemToContract(Request $request, $id = 0)
        {
            $warehouse = Warehouse::where('establishment_id', auth()->user()->establishment_id)->first();

            $items = Item::orderBy('description')->whereIsActive()
                // ->with(['warehouses' => function($query) use($warehouse){
                //     return $query->where('warehouse_id', $warehouse->id);
                // }])
                ->get();
            return self::TransformModalToContract($items);
        }

        /**
         * @param                $items
         * @param Warehouse|null $warehouse
         *
         * @return mixed
         */
        public static function TransformModalToContract($items, Warehouse $warehouse = null)
        {
            return $items->transform(function ($row) use ($warehouse) {
                $full_description = self::getFullDescriptionToContract($row);
                // $full_description = ($row->internal_id)?$row->internal_id.' - '.$row->description:$row->description;
                return [
                    'id' => $row->id,
                    'full_description' => $full_description,
                    'description' => $row->description,
                    'currency_type_id' => $row->currency_type_id,
                    'currency_type_symbol' => $row->currency_type->symbol,
                    'sale_unit_price' => $row->sale_unit_price,
                    'purchase_unit_price' => $row->purchase_unit_price,
                    'unit_type_id' => $row->unit_type_id,
                    'sale_affectation_igv_type_id' => $row->sale_affectation_igv_type_id,
                    'purchase_affectation_igv_type_id' => $row->purchase_affectation_igv_type_id,
                    'is_set' => (bool)$row->is_set,
                    'has_igv' => (bool)$row->has_igv,
                    'calculate_quantity' => (bool)$row->calculate_quantity,
                    'item_unit_types' => collect($row->item_unit_types)->transform(function ($row) {
                        return [
                            'id' => $row->id,
                            'description' => "{$row->description}",
                            'item_id' => $row->item_id,
                            'unit_type_id' => $row->unit_type_id,
                            'quantity_unit' => $row->quantity_unit,
                            'price1' => $row->price1,
                            'price2' => $row->price2,
                            'price3' => $row->price3,
                            'price_default' => $row->price_default,
                        ];
                    }),
                    'warehouses' => collect($row->warehouses)->transform(function ($row) {
                        return [
                            'warehouse_id' => $row->warehouse->id,
                            'warehouse_description' => $row->warehouse->description,
                            'stock' => $row->stock,
                        ];
                    })
                ];
            });
        }

        /**
         * @param Item $item
         *
         * @return string
         */
        public static function getFullDescriptionToContract(Item $item)
        {

            $desc = ($item->internal_id) ? $item->internal_id . ' - ' . $item->description : $item->description;
            $category = ($item->category) ? " - {$item->category->name}" : "";
            $brand = ($item->brand) ? " - {$item->brand->name}" : "";

            $desc = "{$desc} {$category} {$brand}";

            return $desc;
        }
    }