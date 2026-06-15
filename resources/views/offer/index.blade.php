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
    <div class="card shadow col-sm-12">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"> Protocol List</h6>
        </div>

	  <div class="card-body">
                <div class="form-group row">
        <div class="col-lg-12">
            <h1 class="mt-4">Protocol List</h1>
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Protocol No</th>
                        <th>Equipment</th>
                        <th>Title</th>
                     
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($offers as $offer)
                        <tr>
                            <td>{{ $offer->facility_section }}</td>
                            <td></td>
                            <td>{{ $offer->document_no }}</td>
                            <td>{{ $offer->company }}</td>
                            <td>{{ $offer->equipment_name }}</td>
                            <td>{{ $offer->created_at }}</td>
                            <td><a href="{{route('offers.edit',$offer->id)}}">EDIT</a>
                              
                                <!-- Add other action buttons if needed -->
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

    </div>
</div>
@endsection
