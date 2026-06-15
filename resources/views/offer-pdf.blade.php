<!DOCTYPE html>
<html>
<head>
    <title>Offer PDF</title>
    <style>
        /* Ensure that the margin is set for the entire page */
        @page {
            margin: 0.5in; /* Adjust margin as needed */
        }
        
        /* Position the header with the logo in the top-left corner */
        header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            padding: 10px 0;
            border-bottom: 1px solid #000;
        }

        header img {
            width: 50px; /* Adjust width as needed */
            height: auto;
            margin-left: 10px; /* Adjust margin as needed */
        } .page-break {
            page-break-before: always;
        }

        /* Ensure the body content does not overlap with the header */
        body {
            margin-top: 70px; /* Adjust to ensure space for the header */
        }
		.bss{width:300px !important}
		.bss table{ font-size:10px; width:200px !important}
		.bss table tr{  width:200px !important}
    </style>
</head>
<body>
    <header>
        <img src="{{ public_path('images/logo.png') }}" alt="Logo">
    </header>

    <main>
        <h1>{{ $offerTitle }}</h1>
        <p><strong>Offer No:</strong> {{ $offerNumber }}</p>
        <p><strong>Date:</strong> {{ $currentDate }}</p>
        <p><strong>To:</strong> {{ $company }}, {{ $location }}</p>
		
        <p>Kind Attn.- Mr. Satish Babu </p>
 
        <p>Sub/ref: As per your mail Dated 03-07-2024</p>
		
        <p>Dear Sir,</p>

        <p>Integral Solutions is a company providing a wide range of automation solutions, process control instrumentation, power solutions, reporting system solutions, electrical panel solutions, and annual maintenance & service contracts.</p>

        <p>We are a team of highly professional and skilled engineers with years of industry experience. We provide a complete developmental cycle of Industrial Automation from designing to installation, trials, testing, and software development in an innovative and economical way. Our products include:</p>

        <ul>
            <li>Automation Solutions as per 21 CFR Guidelines</li>
            <li>Remote SCADA/DAS Systems</li>
            <li>PLC/PAC Programming/Services</li>
            <li>Customised PLC /Remote IO Panel</li>
            <li>VFD Panel with Solutions</li>
            <li>Servo/Motion Control System</li>
            <li>Energy Monitoring System</li>
            <li>Condition Monitoring System</li>
            <li>Drive Repairing</li>
            <li>PLC, CSV Validation Services</li>
        </ul>

        <p>In the event that any further information is required, please let us know. We shall be pleased to furnish the same.</p>

        <h2 class="page-break">Annexure I – Our Offer</h2>
		
	  <table border="1" cellpadding="2">
    <thead>
        <tr>
            <th>S No</th>
            <th>Description</th>
            <th>Make</th>
            <th>Qty</th>
            <th>Rate</th>
            <th>Amount</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item['description'] }}</td>
                <td>{{ $item['make'] }}</td>
                <td>{{ $item['qty'] }}</td>
                <td>{{ $item['rate'] }}</td>
                <td>{{ $item['amount'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

 <div class="bs">
		{!! $notes !!}
</div>
        <div class="bss">
		{!! $bss !!}
</div>
         <h2 class="page-break">Annexure II - Commercial Terms and Conditions</h2>
        <p>GST Number: 23AAEFI8245D1ZN</p>
        <p>Billing/PO <p>
		
		
		<p style="margin: 0in; text-align: justify; line-height: normal; font-size: 11pt; font-family: Calibri, sans-serif;"><strong><span style="font-size: 12pt; font-family: 'Times New Roman', serif;">Thanks &amp; Regards,</span></strong></p>
<p style="margin: 0in; text-align: justify; line-height: normal; font-size: 11pt; font-family: Calibri, sans-serif;"><strong><em><span style="font-size: 12pt; font-family: 'Times New Roman', serif;">&nbsp;</span></em></strong></p>
<p style="margin: 0in; text-align: justify; line-height: normal; font-size: 11pt; font-family: Calibri, sans-serif;"><strong><em><span style="font-size: 12pt; font-family: 'Times New Roman', serif;">{{ $companyc->company }}</span></em></strong></p>
<p style="margin: 0in; text-align: justify; line-height: normal; font-size: 11pt; font-family: Calibri, sans-serif;"><strong><em><span style="font-size: 12pt; font-family: 'Times New Roman', serif;">An ISO 9001:2015 Certified Company</span></em></strong></p>
<p style="margin: 0in; text-align: justify; line-height: normal; font-size: 11pt; font-family: Calibri, sans-serif;"><strong><em><span style="font-size: 12pt; font-family: 'Times New Roman', serif;">{{ $companyc->name }}</span></em></strong></p>
<p style="margin: 0in; text-align: justify; line-height: normal; font-size: 11pt; font-family: Calibri, sans-serif;"><strong><em><span style="font-size: 12pt; font-family: 'Times New Roman', serif;">Mob- {{ $companyc->phone }}</span></em></strong></p>
<p style="margin: 0in; text-align: justify; line-height: normal; font-size: 11pt; font-family: Calibri, sans-serif;"><strong><em><span style="font-size: 12pt; font-family: 'Times New Roman', serif;">{{ $companyc->email }}</span></em></strong></p>

