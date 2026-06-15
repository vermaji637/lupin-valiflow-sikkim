@extends('layouts.app')

@section('content')
<div class="container">

    <h4>Documents Master</h4>

    {{-- Success Message --}}
    @if(session('success'))
        <div class="alert alert-success">
            {{ session('success') }}
        </div>
    @endif

    {{-- Validation Error --}}
    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif


    {{-- ================= DOCUMENT MASTER FORM ================= --}}
    @if(auth()->check() && auth()->user()->isAdmin())

    <form class="esign-required" method="POST" action="{{ route('documents.store') }}">
        @csrf

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Protocol Type</th>
                    <th>Equipment ID</th>
                    <th>Equipment</th>
                    <th>Facility</th>
                    <th>Document Text</th>
                </tr>
            </thead>

            <tbody>
                <tr>

                    {{-- Protocol Type --}}
                    <td>
                        <select class="form-control" name="protocol_type_id" required>
                            <option value="">-- Select Protocol Type --</option>
                            @foreach($protocolTypes as $type)
                                <option value="{{ $type->id }}">
                                    {{ $type->protocol_type_name }}
                                </option>
                            @endforeach
                        </select>
                    </td>

                    {{-- Equipment ID --}}
                    <td>
                        <select class="form-control" name="equipment_id" required>
                            <option value="">-- Select Equipment --</option>
                            @foreach($equipments as $equipment)
                                <option value="{{ $equipment->id }}">
                                    {{ $equipment->equipment_id }}
                                </option>
                            @endforeach
                        </select>
                    </td>

                    {{-- Equipment Name --}}
                    <td>
                        <select class="form-control" name="equipment_name" required>
                            <option value="">-- Select Equipment --</option>
                            @foreach($equipments as $equipment)
                                <option value="{{ $equipment->id }}">
                                    {{ $equipment->equipment_name }}
                                </option>
                            @endforeach
                        </select>
                    </td>

                    {{-- Facility --}}
                    <td>
                        <select class="form-control" name="facility_id" required>
                            <option value="">-- Select Facility --</option>
                            @foreach($facilities as $facility)
                                <option value="{{ $facility->id }}">
                                    {{ $facility->facility_name }}
                                </option>
                            @endforeach
                        </select>
                    </td>
                    <td>
                        <input
                type="text"
                name="doc_title"
                class="form-control"
                placeholder="Enter document text"
                disabled
            />
                    </td>

                </tr>
            </tbody>
        </table>




        {{-- ===== SUBMIT BUTTON ===== --}}
        <div class="mt-3">
            <button type="submit" class="btn btn-success">
                Submit
            </button>
        </div>

    </form>

    @endif
    {{-- ================= FORM END ================= --}}



    {{-- ================= DOCUMENT RECORD SECTION ================= --}}

    <hr>
    <h5>Documents Records</h5>

    <table class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>#</th>
                <th>Documents</th>
                <th>Protocol Type</th>
                <th>Equipment ID</th>
                <th>Equipment Name</th>
                <th>Facility</th>
                 @if(auth()->check() && auth()->user()->isAdmin())
                <th>Document Title</th>
                  @endif
                <th>Created At</th>

                @if(auth()->check() && !auth()->user()->isAdmin())
                <th>Document Text</th>
                
                @endif
            </tr>
        </thead>

        <tbody>
@forelse($documents as $index => $doc)
<tr>
    <td>{{ $index + 1 }}</td>
    <td>{{ $doc->title ?? '-' }}</td>
    <td>{{ $doc->protocolType->protocol_type_name ?? '-' }}</td>
    <td>{{ $doc->equipment->equipment_id ?? '-' }}</td>
    <td>{{ $doc->equipment->equipment_name ?? '-' }}</td>
    <td>{{ $doc->facility->facility_name ?? '-' }}</td>
     @if(auth()->check() && auth()->user()->isAdmin())
    <td>{{ $doc->doc_title  }}</td>
        @endif
    <td>{{ $doc->created_at->format('d-m-Y') }}</td>
    
    @if(auth()->check() && !auth()->user()->isAdmin())
    <td>
        <form method="POST"
              action="{{ route('documents.submit-text') }}"
              class="esign-required">
            @csrf
            <input type="hidden" name="document_id" value="{{ $doc->id }}">

        
                <input type="text"
                       name="doc_title"
                       class="form-control"
                       value="{{ $doc->doc_title ?? '' }}"
                       placeholder="Enter document text">
            
                <button type="submit" class="btn btn-success btn-sm">
                    Submit
                </button>
            
        </form>
        </td>
    @endif
</tr>
@empty
<tr>
    <td colspan="{{ auth()->check() && !auth()->user()->isAdmin() ? 9 : 7 }}"
        class="text-center">
        No records found.
    </td>
</tr>
@endforelse
</tbody>

    </table>

    {{-- ================= DOCUMENT RECORD SECTION END ================= --}}

</div>
@endsection
