<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use Modules\FrontendController;
use Illuminate\Http\Request;
use Auth;
use App\Models\Property;
use App\Models\Location;
use App\Models\Attributes;
use Modules\Booking\Models\Booking;
use App\Models\PropertyTerm;
use App\Models\PropertyTranslation;
use App\Models\PropertyCategory;
use Illuminate\Support\Facades\DB;

class ManagePropertyController extends Controller
{
    protected $propertyClass;
    protected $propertyTranslationClass;
    protected $propertyTermClass;
    protected $attributesClass;
    protected $locationClass;
    protected $propertyCategoryClass;
    protected $bookingClass;

    public function __construct()
    {

        $this->propertyClass = Property::class;
        $this->propertyTranslationClass = PropertyTranslation::class;
        $this->propertyTermClass = PropertyTerm::class;
        $this->attributesClass = Attributes::class;
        $this->locationClass = Location::class;
        $this->propertyCategoryClass = PropertyCategory::class;
        $this->bookingClass = Booking::class;
    }
    public function callAction($method, $parameters)
    {
        if(!Property::isEnable())
        {
            return redirect('/');
        }
        return parent::callAction($method, $parameters); // TODO: Change the autogenerated stub
    }

    public function manageProperty(Request $request)
    {

        $user_id = Auth::id();
        $rows = $this->propertyClass::query()->select("properties.*")->where("properties.create_user", $user_id);

        if (!empty($search = $request->input("s"))) {
            $rows->where(function($query) use ($search) {
                $query->where('properties.title', 'LIKE', '%' . $search . '%');
                $query->orWhere('properties.content', 'LIKE', '%' . $search . '%');
            });

            if( setting_item('site_enable_multi_lang') && setting_item('site_locale') != app_get_locale() ){
                $rows->leftJoin('property_translations', function ($join) use ($search) {
                    $join->on('properties.id', '=', 'property_translations.origin_id');
                });
                $rows->orWhere(function($query) use ($search) {
                    $query->where('property_translations.title', 'LIKE', '%' . $search . '%');
                    $query->orWhere('property_translations.content', 'LIKE', '%' . $search . '%');
                });
            }
        }

        if (!empty($filterSelect = $request->input("select_filter"))) {
            if ($filterSelect == 'recent') {
                $rows->orderBy('properties.id','desc');
            }

            if ($filterSelect == 'old') {
                $rows->orderBy('properties.id','asc');
            }

            if ($filterSelect == 'featured') {
                $rows->where('properties.is_featured','=', 1);
            }
        } else {
            $rows->orderBy('properties.id','desc');
        }

        $data = [
            'rows' => $rows->paginate(5),
            'breadcrumbs'        => [
                [
                    'name' => __('Manage Properties'),
                    'url'  => route('user.property.index')
                ],
                [
                    'name'  => __('All'),
                    'class' => 'active'
                ],
            ],
            'page_title'         => __("Manage Properties"),
        ];
        return view('user.property.index', $data);
    }


    public function createProperty(Request $request)
    {

        $row = new $this->propertyClass();

        $data = [
            'row'           => $row,
            'translation' => new $this->propertyTranslationClass(),
            'property_category'    => $this->propertyCategoryClass::where('status', 'publish')->get()->toTree(),
            'property_location' => $this->locationClass::where("status","publish")->get()->toTree(),
            'attributes'    => $this->attributesClass::where('service', 'property')->get(),
            'breadcrumbs'        => [
                [
                    'name' => __('Manage Properties'),
                    'url'  => route('user.property.index')
                ],
                [
                    'name'  => __('Create'),
                    'class' => 'active'
                ],
            ],
            'page_title'         => __("Create Properties"),
        ];



        return view('user.property.create', $data);
    }


    public function store( Request $request, $id ){


        $videoimage = $request->file('banner_image_id');
        $mainimage = $request->file('image_id');
        $gallery = $request->file('gallery');

        $input = $request->except(['banner_image_id', 'image_id', 'gallery']);

        if($videoimage){

            $fileName = $videoimage->getClientOriginalName();

            $fileName = uniqid() . $fileName;

            $videoimage->move('images/', $fileName);

            $input['banner_image_id'] = $fileName;

       }


        if($mainimage){


            $fileName = $mainimage->getClientOriginalName();

            $fileName = uniqid() . $fileName;

            $mainimage->move('images/', $fileName);

            $input['image_id'] = $fileName;

       }

       $photos = [];

        if($gallery){

            foreach($gallery as $photo){

            $fileName = $photo->getClientOriginalName();

            $fileName = uniqid() . $fileName;

            $photo->move('images/', $fileName);

            $photos[] = $fileName;

            }

         $input['gallery'] = implode(',', $photos);

       }

       $input['create_user'] = Auth::user()->id;

       $input['status'] = "pending";

      $create =  Property::create($input);

        $res = $create->saveOriginOrTranslation($request->input('lang'),true);

        if ($res) {

            if(!$request->input('lang') or is_default_lang($request->input('lang'))) {

                try {

                    $this->saveTerms($create, $request);

                } catch (\Throwable $th) {

                    dd($th);
                }

            }

            return redirect(route('user.property.edit',['id'=>$create->id]))->with('success', __('Property created') );
        }
    }

    public function saveTerms($row, $request)
    {
        if (empty($request->input('terms'))) {
            $this->propertyTermClass::where('target_id', $row->id)->delete();
        } else {
            $term_ids = $request->input('terms');
            foreach ($term_ids as $term_id) {
                $this->propertyTermClass::firstOrCreate([
                    'term_id' => $term_id,
                    'target_id' => $row->id
                ]);
            }
            $this->propertyTermClass::where('target_id', $row->id)->whereNotIn('term_id', $term_ids)->delete();
        }
    }

    public function editProperty(Request $request, $id)
    {

        $user_id = Auth::id();

        $row = $this->propertyClass::where("create_user", $user_id);

        $row = $row->find($id);

        if (empty($row)) {
            return redirect(route('user.property.index'))->with('warning', __('Property not found!'));
        }

        $translation = $row->translateOrOrigin($request->query('lang'));

        $data = [
            'translation'    => $translation,
            'row'           => $row,
            'property_category'    => $this->propertyCategoryClass::where('status', 'publish')->get()->toTree(),
            'property_location' => $this->locationClass::where("status","publish")->get()->toTree(),
            'attributes'    => $this->attributesClass::where('service', 'property')->get(),
            "selected_terms" => $row->terms->pluck('term_id'),
            'breadcrumbs'        => [
                [
                    'name' => __('Manage Properties'),
                    'url'  => route('user.property.index')
                ],
                [
                    'name'  => __('Edit'),
                    'class' => 'active'
                ],
            ],
            'page_title'         => __("Edit Properties"),
        ];

        return view('user.property.edit', $data);
    }

    public function deleteProperty($id)
    {

        $user_id = Auth::id();
        $query = $this->propertyClass::where("create_user", $user_id)->where("id", $id)->first();
        if(!empty($query)){
            $query->delete();
        }
        return redirect(route('user.property.index'))->with('success', __('Delete property success!'));
    }

    public function bulkEditProperty($id , Request $request){

        $action = $request->input('action');
        $user_id = Auth::id();
        $query = $this->propertyClass::where("create_user", $user_id)->where("id", $id)->first();
        if (empty($id)) {
            return redirect()->back()->with('error', __('No item!'));
        }
        if (empty($action)) {
            return redirect()->back()->with('error', __('Please select an action!'));
        }
        if(empty($query)){
            return redirect()->back()->with('error', __('Not Found'));
        }
        switch ($action){
            case "make-hide":
                $query->status = "draft";
                break;
            case "make-publish":
                $query->status = "publish";
                break;
        }
        $query->save();
        return redirect()->back()->with('success', __('Update success!'));
    }

    public function bookingReport(Request $request)
    {
        $data = [
            'bookings' => $this->bookingClass::getBookingHistory($request->input('status'), false , Auth::id() , 'property'),
            'statues'  => config('booking.statuses'),
            'breadcrumbs'        => [
                [
                    'name' => __('Manage Property'),
                    'url'  => route('user.property.index')
                ],
                [
                    'name' => __('Booking Report'),
                    'class'  => 'active'
                ]
            ],
            'page_title'         => __("Booking Report"),
        ];
        return view('user.property.bookingReport', $data);
    }

    public function bookingReportBulkEdit($booking_id , Request $request){
        $status = $request->input('status');
        if (!empty(setting_item("property_allow_vendor_can_change_their_booking_status")) and !empty($status) and !empty($booking_id)) {
            $query = $this->bookingClass::where("id", $booking_id);
            $query->where("vendor_id", Auth::id());
            $item = $query->first();
            if(!empty($item)){
                $item->status = $status;
                $item->save();
                $item->sendStatusUpdatedEmails();
                return redirect()->back()->with('success', __('Update success'));
            }
            return redirect()->back()->with('error', __('Booking not found!'));
        }
        return redirect()->back()->with('error', __('Update fail!'));
    }

	public function cloneProperty(Request $request,$id){

		$user_id = Auth::id();
		$row = $this->propertyClass::where("create_user", $user_id);
		$row = $row->find($id);
		if (empty($row)) {
			return redirect(route('user.property.index'))->with('warning', __('Property not found!'));
		}
		try{
			$clone = $row->replicate();
			$clone->status  = 'draft';
			$clone->push();
			if(!empty($row->terms)){
				foreach ($row->terms as $term){
					$e= $term->replicate();
					if($e->push()){
						$clone->terms()->save($e);

					}
				}
			}
			if(!empty($row->meta)){
				$e= $row->meta->replicate();
				if($e->push()){
					$clone->meta()->save($e);

				}
			}
			if(!empty($row->translations)){
				foreach ($row->translations as $translation){
					$e = $translation->replicate();
					$e->origin_id = $clone->id;
					if($e->push()){
						$clone->translations()->save($e);
					}
				}
			}

			return redirect()->back()->with('success',__('Property clone was successful'));
		}catch (\Exception $exception){
			$clone->delete();
			return redirect()->back()->with('warning',__($exception->getMessage()));
		}
	}
}