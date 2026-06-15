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
@if ($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
    {{-- Create Form --}}
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
                    <th>Action</th>
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

                    {{-- Equipment --}}
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

                    {{-- Submit --}}
                    <td>
                        <button type="submit" class="btn btn-success">
                            Submit
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </form>
@endif
    {{-- Listing Section --}}
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
                <th>Created At</th>
                @if(auth()->check() && !auth()->user()->isAdmin())
                <th>Approver</th>
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
                    <td>{{ $doc->created_at->format('d-m-Y') }}</td>
                    @if(auth()->check() && !auth()->user()->isAdmin())
                    <td> <button class="btn btn-success">Approve</button></td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="text-center">
                        No records found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
