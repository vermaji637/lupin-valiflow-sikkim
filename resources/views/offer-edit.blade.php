@extends('layouts.app')

@section('title', 'Add Users')

@section('content')
   <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
   <script src="https://cdn.tiny.cloud/1/l0ipxw3vdbs9h9h6xf2ceib3itire1rlb5b8dyautd7qy83z/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>
   
<div class="container-fluid">

    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Create Propsal</h1>
        <a href="{{route('users.index')}}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm"><i
                class="fas fa-arrow-left fa-sm text-white-50"></i> Back</a>
    </div>

    {{-- Alert Messages --}}
    @include('common.alert')
   
    <!-- DataTales Example -->
    <div class="card shadow col-sm-9">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Add New Propsal</h6>
        </div>
	<form action="{{ route('offers.update', $offer->id) }}" method="POST">
	  <div class="card-body">
                <div class="form-group row">
    @csrf
    @method('PUT')
    
    <input type="text" name="offerTitle" value="{{ $offer->offer_title }}" required>
    <input type="text" name="offerNumber" value="{{ $offer->offer_number }}" required>
    <input type="text" name="company" value="{{ $offer->company }}" required>
    <input type="text" name="location" value="{{ $offer->location }}" required>

    <!-- Loop through items to allow editing -->
    @foreach($offer->items as $item)
        <input type="text" name="description[]" value="{{ $item->description }}" required>
        <input type="text" name="make[]" value="{{ $item->make }}" required>
        <input type="number" name="qty[]" value="{{ $item->qty }}" required>
        <input type="number" name="rate[]" value="{{ $item->rate }}" required>
    @endforeach
 </div>   
			  	  </div> 
    <button type="submit">Update Offer</button>
</form>

   
    </div>

</div>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Requalification AHU-005 (Editable HTML)</title>
  <style>
    body{font-family: Arial, Helvetica, sans-serif; line-height:1.5; margin:24px; color:#111;}
    header{display:flex; align-items:center; gap:12px; margin-bottom:24px;}
    .doc-title{font-size:22px; font-weight:700;}
    .subtle{color:#444;}
    .toc, .section{margin: 18px 0;}
    h1, h2, h3 { margin: 16px 0 8px; }
    h1{font-size:20px; border-bottom:2px solid #ddd; padding-bottom:4px;}
    h2{font-size:18px;}
    h3{font-size:16px;}
    .note{background:#fff7cc; border:1px solid #ffe58f; padding:8px 12px; border-radius:8px; margin:12px 0;}
    .editable{outline:2px dashed #cbd5e1; padding:16px; border-radius:12px; background:#f8fafc;}
    .muted{color:#666;}
    .grid{display:grid; grid-template-columns: 1fr 1fr; gap:12px;}
    table{border-collapse:collapse; width:100%; margin:12px 0;}
    th, td{border:1px solid #ddd; padding:8px; vertical-align:top;}
    th{background:#f2f2f2; text-align:left;}
    .footer{margin-top:40px; font-size:12px; color:#666;}
    .toolbar{position:sticky; top:0; background:#fff; padding:10px 0; border-bottom:1px solid #eee; margin-bottom:16px;}
    .toolbar button{padding:8px 12px; margin-right:8px; border:1px solid #ccc; background:#f6f6f6; border-radius:8px; cursor:pointer;}
    .toolbar .hint{font-size:12px; color:#666; margin-left:8px;}
    @media print {
      .toolbar, .note { display:none; }
      .editable { outline:none; background:transparent; }
      body { margin: 0.5in; }
    }
  </style>
</head>
<body>
  <div class="toolbar">
    <button onclick="document.execCommand('bold')">Bold</button>
    <button onclick="document.execCommand('italic')">Italic</button>
    <button onclick="document.execCommand('insertUnorderedList')">• List</button>
    <button onclick="saveFile()">Download HTML</button>
    <span class="hint">Tip: Click anywhere in the content and start typing. Use the buttons for basic formatting.</span>
  </div>

  <header>
    <div>
      <div class="doc-title">PERIODIC REQUALIFICATION PROTOCOL AND REPORT</div>
      <div class="subtle">Requalification AHU-005 — Editable HTML version</div>
    </div>
  </header>

  <div class="note">
    This is an <b>editable</b> HTML version of your DOCX. Click into the area below and edit directly.
    Use <i>Download HTML</i> to save your changes as a file.
  </div>

  <div id="content" class="editable" contenteditable="true">
<p><b>Logo</b><br/>Company Name, (Location)</p>

<p><b>PERIODIC REQUALIFICATION PROTOCOL AND REPORT</b></p>

<p><b>FACILITY/ SECTION</b></p>

<p><b>EQUIPMENT NAME</b></p>

<p><b>EQUIPMENT ID. NO.</b></p>

<p><b>DOCUMENT NO.</b></p>

<p><b>VERSION</b></p>

<p><b>PAGE NO.</b> 23 of 25</p>

<h2>PRE-APPROVAL SIGNATURE</h2>
<p><b>PREPARED BY</b><br/>
NAME &amp; DESIGNATION<br/>
SIGNATURE &amp; DATE<br/>
USER DEPARTMENT (ENGINEERING)</p>

<p><b>CHECKED BY</b><br/>
NAME &amp; DESIGNATION<br/>
SIGNATURE &amp; DATE<br/>
ENGINEERING</p>

<p><b>USER DEPARTMENT</b> (PRODUCTION)</p>
<p><b>VALIDATION</b></p>
<p><b>QUALITY ASSURANCE</b></p>

<p><b>APPROVED BY</b><br/>
NAME &amp; DESIGNATION<br/>
SIGNATURE &amp; DATE<br/>
USER / VALIDATION<br/>
QUALITY ASSURANCE</p>

<p><b>EFFECTIVE DATE:</b><br/>
<b>EFFECTIVE DATE:</b></p>

<h2>TABLE OF CONTENTS</h2>
<p>1.0 OBJECTIVE….. 3<br/>
2.0 SCOPE………… ……….3<br/>
3.0 RESPONSIBILITY 4<br/>
4.0 TYPE OF QUALIFICATION: 4<br/>
5.0 EQUIPMENT /SYSTEM DETAILS 5<br/>
6.0 PRE-REQUISITES 7<br/>
7.0 APPLICABLE STANDARD OPERATING PROCEDURE (SOP’S) 8<br/>
8.0 PRE-QUALIFICATION CHECKS 8<br/>
9.0 REQUALIFICATION CHECKS 12<br/>
10.0 DEVIATIONS (IF ANY) .21<br/>
11.0 SUMMARY &amp; CONCLUSION .21<br/>
12.0 REFERENCES ……………………………………………………………………………………….22<br/>
13.0 ABBREVIATIONS 22<br/>
14.0 REVISION HISTORY……………………………………………………………………………….23</p>

<h1>OBJECTIVE</h1>
<p>The protocol provides guidelines for the requalification of Air Handling Unit ID. No. /ENG/AHU-005 in the facility of Company Name Ltd. Location to establish high degree of assurance that the equipment is maintained at a qualified stage since its initial installation and is fit for its intended use when operated as per applicable SOP.</p>

<h1>SCOPE</h1>
<p>This protocol is applicable for Air Handling Unit as details given in below table.</p>
<p>This protocol provides directions / procedure for requalification to provide documented evidence that Air Handling Unit is operated in accordance with the requirements of the product, process, and safety and cGMP norms.</p>
<p>The subjected equipment is identified as Air Handling Unit ID. No. /ENG/AHU-005 located at Service Floor.</p>

<table>
  <tr><th>Details</th><th>Description</th></tr>
  <tr><td>Tag. No.</td><td>/ENG/AHU-005</td></tr>
  <tr><td>Plant / Module</td><td></td></tr>
  <tr><td>Location</td><td></td></tr>
  <tr><td>MOC</td><td>Aluminum, thermal Break section with internal coving.</td></tr>
  <tr><td>Capacity</td><td>2967 CFM</td></tr>
  <tr><td>Elevation</td><td>NA</td></tr>
  <tr><td>Area Catering to</td><td>Compression-1</td></tr>
</table>

<h1>RESPONSIBILITY</h1>
<p>Responsibilities of different department/ personnel involved in different activities related to the re-qualification are defined below:</p>
<ul>
<li>Engineering department is responsible to prepare &amp; review the re-qualification protocol in consultation with Validation / QA, execution of the requalification and preparation of re-qualification summary report.</li>
<li>User and engineering department shall execute the re-qualification activity.</li>
<li>Engineering department is responsible to review the protocol for adequacy and accuracy, and to provide the equipment maintenance history.</li>
<li>Validation department is responsible to review the requalification protocol and summary report.</li>
<li>Quality Assurance is responsible to provide guidance, wherever required to the related departments, review &amp; approval of the protocol &amp; requalification report.</li>
</ul>

<h1>TYPE OF QUALIFICATION</h1>
<p>As the qualification of Air Handling Unit having I.D. /ENG/AHU-005 has been completed on 13/01/17 as per document no. /PQR/AHU/16-001. Hence periodic re qualification is to be performed as per frequency defined in SOP.</p>

<h3>Qualification History</h3>
<table>
  <tr><th>Qualification</th><th>Details of qualification (Report no and approval date)</th></tr>
  <tr><td>Initial Qualification (PQ)</td><td><b>Report No.</b> /PQR/AHU/16-001<br/><b>Approval Date</b> 13/01/17</td></tr>
  <tr><td>Mention other qualification after initial qualification</td><td>NA</td></tr>
</table>

<h1>EQUIPMENT / SYSTEM DETAILS</h1>
<h3>Details of Air Handling Unit</h3>
<table>
  <tr><th>DETAILS</th><th>DESCRIPTION</th></tr>
  <tr><td>Name of Equipment</td><td>Air Handling Unit</td></tr>
  <tr><td>Name of supplier / manufacturer</td><td>D.J.K Industries</td></tr>
  <tr><td>Model No.</td><td>NA</td></tr>
  <tr><td>Serial No.</td><td>NA</td></tr>
  <tr><td>In-house Equipment / system ID. No.</td><td>/ENG/AHU-005</td></tr>
  <tr><td>AHU Location</td><td>Service Floor</td></tr>
  <tr><td>AHU Dimension</td><td>3800L x 1180W x 1760H mm</td></tr>
  <tr><td>Capacity</td><td>2967 CFM</td></tr>
  <tr><td>MOC of AHU Frame</td><td>Aluminum, thermal Break section with internal coving.</td></tr>
  <tr><td>Area Catering to</td><td>Compression-1</td></tr>
  <tr><td>Area Classification</td><td>ISO-8</td></tr>
  <tr><td>No. of Supply grill</td><td>04 Nos.</td></tr>
  <tr><td>No. of return riser / grill</td><td>05 Nos.</td></tr>
</table>

<h3>Connected Accessories</h3>
<table>
  <tr><th>Connected Accessories</th><th>Description</th></tr>
  <tr><td>Blower / motor</td><td>Plug type Blower ... static pressure of 135 MM of WC.</td></tr>
  <tr><td>Cooling Coil</td><td>Cooling coil made up of SS body, copper tube and aluminium fins ... connected to cooling coil.</td></tr>
  <tr><td>Heating Coil</td><td>Heating coil made up of SS body, copper tube and aluminium fins ... required air condition can be maintain.</td></tr>
  <tr><td>Fresh Air filter</td><td>G-4 (10 micron) with efficiency of 90% down to 10 Micron.</td></tr>
  <tr><td>Pre filter</td><td>F-5 (5-micron) with efficiency of 95% down to 5.0 Micron.</td></tr>
  <tr><td>Fine filter</td><td>F-8 (3 micron) fine filters with efficiency of 98% down to 3.0 Micron.</td></tr>
  <tr><td>Terminal HEPA Filter</td><td>0.3 Micron H-14 HEPA filters ... not washable/cleanable.</td></tr>
  <tr><td>Bleed Air Filter</td><td>0.3 Micron H-14 HEPA ... not washable/cleanable.</td></tr>
  <tr><td>Return air riser filter</td><td>G-4 (10 micron) ... 90% down to 10 Micron.</td></tr>
  <tr><td>Supply &amp; Return Duct</td><td>22 &amp; 24 Gauge GI sheets ... sealed with silicone sealant.</td></tr>
  <tr><td>HEPA box</td><td>GI Powder Coated, bottom Loaded HEPA Filter / Top Entry for Supply Air.</td></tr>
  <tr><td>Volume control damper</td><td>Aluminum Extrusion; separate VCDs throughout.</td></tr>
  <tr><td>Fire Control Damper</td><td>Galvanized steel sheet fire damper in main return air duct.</td></tr>
  <tr><td>Drain Tray</td><td>Stainless Steel ... between chilled and hot water coil.</td></tr>
  <tr><td>Duct Insulation</td><td>Armaflex/Armacell Foam ... 19mm &amp; 13mm thickness.</td></tr>
  <tr><td>VFD</td><td>Danfoss</td></tr>
  <tr><td>VFD Panel</td><td>MS powder coated, detachable type.</td></tr>
</table>

<h1>PRE-REQUISITES</h1>
<p>Protocol shall be approve with identification of all tests to be carried out ... reviewed and approved by all department.</p>

<h1>APPLICABLE STANDARD OPERATING PROCEDURE (SOP’s)</h1>
<table>
  <tr><th>Sr. No.</th><th>Title of the SOP</th><th>SOP No.</th><th>Effective Date</th></tr>
  <tr><td>1</td><td></td><td></td><td></td></tr>
  <tr><td>2</td><td></td><td></td><td></td></tr>
  <tr><td>3</td><td></td><td></td><td></td></tr>
  <tr><td>4</td><td></td><td></td><td></td></tr>
</table>

<h1>PRE-QUALIFICATION CHECKS</h1>
<h3>Calibrations of Instruments</h3>
<p>Verify the calibration status of all the instruments and record the details below. Attach copies of certificates.</p>

<table>
  <tr><th>Sr. No.</th><th>Instrument Name</th><th>Instrument ID</th><th>Date of Calibration</th><th>Due Date</th><th>Checked By (Sign.)</th></tr>
  <tr><td>AHU: 1</td><td>Differential Pressure Gauge (across pre filter)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>2</td><td>Differential Pressure Gauge (across fine filter)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>3</td><td>Pressure gauge (chilled water inlet line)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>4</td><td>Pressure gauge (chilled water outlet line)</td><td></td><td></td><td></td><td></td></tr>
</table>

<table>
  <tr><th>Sr. No.</th><th>Instrument Name</th><th>Instrument ID</th><th>Date of Calibration</th><th>Due Date</th><th>Checked By (Sign.)</th></tr>
  <tr><td>5</td><td>Pressure gauge (hot water inlet line)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>6</td><td>Pressure gauge (hot water outlet line)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>7</td><td>Temperature gauge (chilled water inlet line)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>8</td><td>Temperature gauge (chilled water outlet line)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>9</td><td>Temperature gauge (hot water inlet line)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>10</td><td>Temperature gauge (hot water outlet line)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>11</td><td>Temperature / RH transmitter</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>12</td><td>Differential pressure Transmitter</td><td></td><td></td><td></td><td></td></tr>
</table>

<h3>Dust Extractor</h3>
<table>
  <tr><th>Sr. No.</th><th>Instrument Name</th><th>Instrument ID</th><th>Date of Calibration</th><th>Due Date</th><th>Checked By</th></tr>
  <tr><td>1</td><td>Differential Pressure Gauge (across Cartridge Filter)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>2</td><td>Differential Pressure Gauge (across fine filter)</td><td></td><td></td><td></td><td></td></tr>
  <tr><td>3</td><td>Differential Pressure Gauge (across HEPA filter)</td><td></td><td></td><td></td><td></td></tr>
</table>

<h3>Preventive Maintenance Details</h3>
<table>
  <tr><th>Sr. No.</th><th>Equipment Name / ID</th><th>Done on</th><th>Due on</th><th>Checked by (Engineering) (Sign./Date)</th></tr>
  <tr><td></td><td></td><td></td><td></td><td></td></tr>
</table>

<h3>Any other specific tests</h3>
<ul>
  <li>Airflow volume measurement / ACPH</li>
  <li>HEPA filter Integrity test</li>
  <li>Non-Viable particulate count test</li>
  <li>Area Recovery test</li>
  <li>Airflow Pattern test</li>
  <li>Dust Extractor CFM measurement</li>
</ul>

<h3>Verification with the current P &amp; ID</h3>
<p>&nbsp;</p>

<p><b>History card from date:</b> ______ to _________.<br/>
<b>Reviewed by (Engineering Department)</b><br/>
<b>Recommendation:</b><br/><br/>
Engineering shall review the history card. In case of any repeated failure, appropriate action shall be recommended by engineering team and record in this document.</p>

<p><b>History card from date:</b> ______ to _________.<br/>
<b>Reviewed by (Engineering Department)</b><br/>
<b>Recommendation:</b><br/><br/>
Engineering shall review the history card. In case of any repeated failure, appropriate action shall be recommended by engineering team and record in this document.</p>

<h3>History of Equipment / system</h3>
<p>&nbsp;</p>

<h3>Review of Change Controls related to the Equipment / system</h3>
<p>Review the Change Controls related to the Equipment / system under requalification and summarize;</p>

<table>
  <tr><th>S. No.</th><th>Ref. Change Control No.</th><th>Summary of Changes</th></tr>
  <tr><td></td><td></td><td></td></tr>
</table>

<p><b>Remarks / Recommendation (if any):</b><br/>
……………………………………………………………………………………………………………<br/>
……………………………………………………………………………………………………………<br/>
……………………………………………………………………………………………………………</p>

<p><b>Reviewed By:</b> (Sign/Date)</p>

<h1>REQUALIFICATION CHECKS</h1>
<h3>Installation Re-qualification checks</h3>
<table>
  <tr><th>Checks</th><th>Acceptance criteria</th><th>Observation</th><th>Done by (Engineering)</th></tr>
  <tr><td>Electrical connections</td><td>All the electrical connection in the panel should be connected properly</td><td></td><td></td></tr>
  <tr><td>Check chilled water connections</td><td>Should be available. Inlet Pressure: &gt; 1.0 KG/CM²; Inlet Temperature: 2 to 8°C</td><td></td><td></td></tr>
  <tr><td>Check hot water connections</td><td>Should be available. Inlet Pressure: &gt; 1.0 KG/CM²; Inlet Temperature: ≥ 35.0°C</td><td></td><td></td></tr>
  <tr><td>Leveling/ Centering/ Alignment</td><td>Should be properly leveled / centered / aligned</td><td></td><td></td></tr>
  <tr><td>Access and Clearance</td><td>Adequate space is available for accessing the equipment for operation, cleaning &amp; maintenance</td><td></td><td></td></tr>
</table>

<h3>General Checks: Verification of major components of equipment</h3>
<table>
  <tr><th>Major components</th><th>Checks</th><th>Methodology</th><th>Acceptance criteria</th><th>Observation/ Result</th><th>Done by</th></tr>
  <tr><td>Machine frame and body part</td><td>Check any physical damage to the machine frame &amp; body part.</td><td>Visual Inspection</td><td>No physical damage.</td><td></td><td></td></tr>
  <tr><td>Fresh air filter</td><td></td><td>Visual Inspection</td><td>Filter should not be damaged or choked</td><td></td><td></td></tr>
  <tr><td>Pre filter</td><td></td><td>Visual Inspection</td><td>Filter should not be damaged or choked</td><td></td><td></td></tr>
  <tr><td>Fine filter</td><td></td><td>Visual Inspection</td><td>Filter should not be damaged or choked</td><td></td><td></td></tr>
  <tr><td>Bleed air filter</td><td></td><td>Visual Inspection</td><td>Filter should not be damaged or choked</td><td></td><td></td></tr>
  <tr><td>Terminal HEPA filter</td><td></td><td>Visual Inspection</td><td>Filter should not be damaged or choked</td><td></td><td></td></tr>
  <tr><td>Chilled water coil</td><td></td><td>Visual Inspection</td><td>No leakages from coil tube and header.</td><td></td><td></td></tr>
  <tr><td>Hot water coil</td><td></td><td>Visual Inspection</td><td>No leakages from coil tube and header.</td><td></td><td></td></tr>
  <tr><td>AHU Volume control damper</td><td></td><td>Visual Inspection</td><td>Not damaged; smooth operation.</td><td></td><td></td></tr>
  <tr><td>AHU drain tray and drain line</td><td></td><td>Visual Inspection</td><td>Clean and clear.</td><td></td><td></td></tr>
  <tr><td>AHU supply and return Duct</td><td></td><td>Visual Inspection</td><td>No damage/leakages; properly insulated.</td><td></td><td></td></tr>
  <tr><td>Supply HEPA housing Box</td><td></td><td>Visual Inspection</td><td>No physical damage; VCD smooth; grill fixed properly.</td><td></td><td></td></tr>
  <tr><td>VFD</td><td></td><td>Visual Inspection</td><td>Fixed properly in VFD panel.</td><td></td><td></td></tr>
  <tr><td>VFD / Electrical panel</td><td></td><td>Visual Inspection</td><td>No damage; connections proper; cables dressed.</td><td></td><td></td></tr>
</table>

<h3>Operational qualification checks: Functional Tests</h3>
<p>In order to evaluate correct functioning / operation of the equipment, checks such as start-up check, shut down check, operational checks and safety checks shall be performed.</p>

<table>
  <tr><th>Sr. No</th><th>Identification/ Position of valve</th><th>Acceptance criteria</th><th>Observation</th><th>Done by (Engineering)</th></tr>
  <tr><td>Mechanical Adjustment: 1</td><td>Supply air damper of room terminal boxes and main supply air duct</td><td>Open/close properly; locking arrangement available.</td><td></td><td></td></tr>
  <tr><td>2</td><td>Return air damper of room terminal boxes / return riser ducts and main return air duct</td><td>Open/close properly; locking arrangement available.</td><td></td><td></td></tr>
  <tr><td>3</td><td>Fresh air &amp; Exhaust air damper</td><td>Open/close properly; locking arrangement available.</td><td></td><td></td></tr>
  <tr><td>4</td><td>Fire damper in main return air duct</td><td>Should be in open condition.</td><td></td><td></td></tr>
  <tr><td>5</td><td>Chilled water valve provided in chilled water manifold</td><td>Open/close smoothly.</td><td></td><td></td></tr>
  <tr><td>6</td><td>Hot water valve provided in hot water manifold</td><td>Open/close smoothly.</td><td></td><td></td></tr>
  <tr><td>7</td><td>Chilled water and hot water manifold leakages</td><td>No leakages from joints/fittings.</td><td></td><td></td></tr>
  <tr><td>8</td><td>AHU plenum doors position</td><td>Open/close properly for all sections.</td><td></td><td></td></tr>
  <tr><td>9</td><td>Door gaskets</td><td>Provided in all plenum doors.</td><td></td><td></td></tr>
  <tr><td>10</td><td>Filter fittings in the AHU</td><td>Fresh/pre/fine/bleed filters fixed properly; no side leakages.</td><td></td><td></td></tr>
  <tr><td>11</td><td>Supply grill fittings</td><td>Fixed properly from bottom side.</td><td></td><td></td></tr>
  <tr><td>12</td><td>Return grill fittings</td><td>Fixed properly from bottom side.</td><td></td><td></td></tr>
  <tr><td>13</td><td>Supply and return air duct and duct joints</td><td>Fixed properly; no leakages.</td><td></td><td></td></tr>
</table>

<h3>Machine Performance</h3>
<ol>
  <li>Switch ON the main power supply of AHU electrical panel: RYB lamp glows; VFD screen ON; OFF lamp ON; Temp &amp; RH display on VFD.</li>
  <li>Manual / Auto Mode Select selector switch: Operates smoothly.</li>
  <li>Chilled water supply: Available with &ge; 1.0 KG/CM².</li>
  <li>Hot water supply: Available with &ge; 1.0 KG/CM².</li>
  <li>Auto start AHU: Indication lamps behave as specified; blower ramps via VFD; valves operate by PID action; VFD shows motor frequency; clockwise rotation; no abnormal noise/vibration; no leakages.</li>
  <li>Stop AHU: ON lamp OFF; OFF lamp glows; blower stops gradually to zero.</li>
  <li>AHU blower-motor function: Runs smoothly; no abnormal noise/vibration.</li>
  <li>DP gauge readings: Pre filter 4–19 mmwc; fine filter 5–22 mmwc.</li>
  <li>Door safety interlock: Blower OFF when door open (view lamp ON); Blower ON when door close (view lamp OFF).</li>
  <li>Interlocking with Dust Extractor: DEX OFF when AHU stop; DEX ON when AHU ON.</li>
</ol>

<h1>DEVIATIONS (IF ANY)</h1>
<table>
  <tr><th>Deviation (If any)</th><th>Corrective Actions</th><th>Preventive Actions</th></tr>
  <tr><td></td><td></td><td></td></tr>
</table>

<h1>SUMMARY &amp; CONCLUSION</h1>
<p>Based on thorough review of the available data, it is concluded that, the AHU ID. No. /ENG/AHU-005 meets the Requalification criteria defined in the protocol. The AHU is in good condition and can be operate for regular use.</p>
<p><b>Attachments</b></p>
<ol>
  <li>Airflow volume measurement / ACPH</li>
  <li>HEPA filter Integrity test</li>
  <li>Non-Viable particulate count test</li>
  <li>Area Recovery test</li>
  <li>Airflow Pattern test</li>
  <li>Dust Extractor CFM measurement</li>
  <li>Calibration certificates of instrument attached with AHU</li>
  <li>Calibration certificates of instrument used during requalification of AHU</li>
  <li>Training certificate of personnel involved from third party</li>
</ol>

<h1>REFERENCES</h1>
<p>Installation &amp; Operational qualification documents: /IOQ/AHU/16-001<br/>
Performance Qualification Document: /PQR/AHU/16-001</p>

<h1>ABBREVIATIONS</h1>
<p>AHU - Air Handling Unit<br/>
ACPH - Air Changes Per Hour<br/>
CFM - Cubic Feet per minute<br/>
DQ - Design Qualification<br/>
cGMP - Current good Manufacturing practices<br/>
MOC - Material of Construction<br/>
PIBCV - Pressure Independent Balancing Control Valve<br/>
PID - Proportional Integral Derivative<br/>
URS - User Requirement Specification<br/>
IQ - Installation Qualification<br/>
OQ - Operational Qualification<br/>
IOQ - Installation &amp; Operational Qualification<br/>
PQ - Performance Qualification<br/>
PQR - Performance Qualification Report<br/>
MM of WC - Millimeter of Water Column<br/>
SS - Stainless Steel<br/>
GI - Galvanized Iron<br/>
VFD - Variable Frequency Drive<br/>
KW - Kilo Watt<br/>
P&amp;ID - Piping &amp; Instrumentation Diagram<br/>
KG/CM2 - Kilogram per centimeter square<br/>
°C - Degree Celsius</p>

<h1>REVISION HISTORY</h1>
<table>
  <tr><th>Revision No.</th><th>Effective Date</th><th>Reason for Revision</th></tr>
  <tr><td>00</td><td></td><td>New Document</td></tr>
</table>

<h2>POST-APPROVAL SIGNATURE</h2>
<p><b>PREPARED BY</b><br/>NAME &amp; DESIGNATION<br/>SIGNATURE &amp; DATE<br/>USER DEPARTMENT (ENGINEERING)</p>

<p><b>CHECKED BY</b><br/>NAME &amp; DESIGNATION<br/>SIGNATURE &amp; DATE<br/>ENGINEERING</p>

<p><b>USER DEPARTMENT</b> (PRODUCTION)</p>
<p><b>QUALITY ASSURANCE</b></p>
<p><b>VALIDATION</b></p>

<p><b>APPROVED BY</b><br/>NAME &amp; DESIGNATION<br/>SIGNATURE &amp; DATE<br/>QUALITY ASSURANCE</p>

<p class="muted">ANNEX_xxx_xxx_XXXXX | (1.0) | SOP_XXXX_XXX_00000 | CLASS I<br/>
Page 23 of 24</p>
  </div>

  <div class="footer">
    Exported from DOCX to HTML (editable). You can host this file in Laravel and store edited content via a form post or content serialization.
  </div>
@endsection

