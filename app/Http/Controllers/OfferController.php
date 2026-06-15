<?php
namespace App\Http\Controllers;
use App\Models\Offer;
use App\Models\OfferItem;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use App\Models\Company;
use App\Models\User;
use App\Models\ProtocolType;
use App\Models\Equipment;
use App\Models\Facility;
use App\Models\Document; 
use App\Models\Report; 

class OfferController extends Controller
{
    // Show the form
    public function showForm()
    {
        // Generate the next offer number (replace with actual logic if needed)
        $nextOfferNumber = 'INT/24-25/205-R' . rand(1, 10);
           $users = User::all();
           $documents = Document::where('status', 'completed')->get();
           //dd($documents);
    
          
        // Get current date
        $currentDate = Carbon::now()->format('d-M-Y');

        // Example user details
        $userDetails = [
            'name' => 'Mr. Satish Babu',
            'company' => 'M/s. Lupin Ltd',
            'location' => 'Sikkim',
        ];

        return view('offer-form', compact('nextOfferNumber', 'currentDate', 'userDetails','users','documents'));
    }


 public function index()
{  
    
    $users = User::all(); // Fetch all users
    
    // Retrieve all offers with their associated items
    $offers = Offer::with('items')->orderBy('created_at', 'desc')->get();

    // Pass offers to the view
    return view('offer.index', compact('offers','users'));
}


public function createForm(Request $request)
    {
          $users = User::all();
         $dataArray = $request->all();
         $jsonData = json_encode($dataArray, JSON_PRETTY_PRINT);
         //dd($jsonData);
          //$protocol = Offer::find(31);
          
         
         
         $offer = Offer::create([
        'facility_section' => $request->facility_section ?? 'Not Specified',
        'document_no' => (int) $request->document_id,
        'company' => $request->company_name,
        'version' =>  $request->version,
        'equipment_name' =>  $request->equipment_name,
        'equipment_id' =>  $request->equipment_id,
        'protocol'=>$jsonData,
        'offer_date' => Carbon::now()->format('Y-m-d'),
    ]);
        $protocol = Offer::find($offerId = $offer->id);
         $protocolarray = json_decode($protocol->protocol, true); 
   
        // dd($jsonData);
         $pdf = Pdf::loadView('protocol-pdf',['users' => $users,'protocolarray'=>$protocolarray]);
        
    return   $pdf->download('offer.pdf');

    }

    // Generate the offer PDF
    public function generateOffer(Request $request)
    {
        $data = $request->all();
        $company = Company::find(1);
		
        $data['currentDate'] = Carbon::now()->format('d-M-Y');
		 $items = [];
		
for ($i = 0; $i < count($data['description']); $i++) {
    $items[] = [
        'description' => $data['description'][$i],
        'make'        => $data['make'][$i],
        'qty'         => $data['qty'][$i],
        'rate'        => $data['rate'][$i],
        'amount'      => $data['amount'][$i]
    ];
}

 $request->validate([
        'offerTitle' => 'required|string',
        'offerNumber' => 'required|string|unique:offers,offer_number',
        'company' => 'required|string',
        'location' => 'required|string',
        'description.*' => 'required|string',
        'make.*' => 'required|string',
        'qty.*' => 'required|integer',
        'rate.*' => 'required|numeric',
    ]);
	 $offer = Offer::create([
        'offer_title' => $request->input('offerTitle'),
        'offer_number' => $request->input('offerNumber'),
        'company' => $request->input('company'),
        'location' => $request->input('location'),
        'bss' => $request->input('bss'),
        'notes' => $request->input('notes'),

        'offer_date' => Carbon::now()->format('Y-m-d'),
    ]);
	 for ($i = 0; $i < count($request->input('description')); $i++) {
        OfferItem::create([
            'offer_id' => $offer->id,
            'description' => $request->input('description')[$i],
            'make' => $request->input('make')[$i],
            'qty' => $request->input('qty')[$i],
            'rate' => $request->input('rate')[$i],
            'amount' => $request->input('qty')[$i] * $request->input('rate')[$i],
        ]);
    }

        $pdf = Pdf::loadView('offer-pdf', $data,['items' => $items, 'companyc'=>$company ]);
        return $pdf->download('offer.pdf');
    }
	
		public function edit(Request $request, $id)
{
	 $offer = Offer::find($id);
	return view('offer.edit', compact('offer'));
}
	
	public function update(Request $request, $id)
{

    // Validate the request data
    $request->validate([
        'offerTitle' => 'required|string',
        'offerNumber' => 'required|string|unique:offers,offer_number,' . $id,  // Make sure the offer number is unique except for the current offer
        'company' => 'required|string',
        'location' => 'required|string',
        'description.*' => 'required|string',
        'make.*' => 'required|string',
        'qty.*' => 'required|integer',
        'rate.*' => 'required|numeric',
    ]);

    // Find the existing offer
    $offer = Offer::findOrFail($id);

    // Update offer fields
    $offer->update([
        'offer_title' => $request->input('offerTitle'),
        'offer_number' => $request->input('offerNumber'),
        'company' => $request->input('company'),
        'location' => $request->input('location'),
        'bss' => $request->input('bss'),
        'notes' => $request->input('notes'),
        'offer_date' => Carbon::now()->format('Y-m-d'),  // Optional: You can retain the original offer date if needed
    ]);

    // Remove existing offer items associated with this offer (optional)
    OfferItem::where('offer_id', $offer->id)->delete();

    // Add updated offer items
    $offerItems = [];
    for ($i = 0; $i < count($request->input('description')); $i++) {
        $offerItems[] = [
            'offer_id' => $offer->id,
            'description' => $request->input('description')[$i],
            'make' => $request->input('make')[$i],
            'qty' => $request->input('qty')[$i],
            'rate' => $request->input('rate')[$i],
            'amount' => $request->input('qty')[$i] * $request->input('rate')[$i],
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // Re-insert the updated items
    OfferItem::insert($offerItems);

    return response()->json(['message' => 'Offer updated successfully']);
}

	
	
}

