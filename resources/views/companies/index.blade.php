
@extends('layouts.app')

@section('title', 'companys List')

@section('content')
    <div class="container-fluid">

        <!-- Page Heading -->
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">companys</h1>
            <div class="row">
                <div class="col-md-6">
                    <a href="{{ route('companies.create') }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus"></i> Add New
                    </a>
                </div>
                <div class="col-md-6">
                    
                </div>
                
            </div>

        </div>

        {{-- Alert Messages --}}
        @include('common.alert')

        <!-- DataTales Example -->
        <div class="card shadow mb-4">
            <div class="card-header py-3">
                <h6 class="m-0 font-weight-bold text-primary">All Company</h6>

            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered" id="dataTable" width="100%" cellspacing="0">
                        <thead>
                            <tr>
                                <th width="20%">Name</th>
                                <th width="25%">Email</th>
                                <th width="15%">Mobile</th>
                                <th width="15%">Address</th>
								    <th width="15%">City</th>
									    <th width="15%">State</th>
                                <th width="15%">Postal Code</th>
                                <th width="10%">Country</th>
								  <th width="10%"></th>
								    <th width="10%"></th>
                            </tr>
                        </thead>
                        <tbody>
                           @foreach ($companies as $company)
                                <tr>
                                    <td>{{ $company->name }}</td>
                                    <td>{{ $company->email }}</td>
                                    <td>{{ $company->phone }}</td>
                                    <td>{{ $company->address}}</td>
									 <td>{{ $company->city}}</td>
									  <td>{{ $company->state}}</td>
									   <td>{{ $company->postal_code}}</td>
									    <td>{{ $company->country}}</td>
                                    <td>
                                        @if ($company->status == 0)
                                            <span class="badge badge-danger">Inactive</span>
                                        @elseif ($company->status == 1)
                                            <span class="badge badge-success">Active</span>
                                        @endif
                                    </td>
                                    <td style="display: flex">
                                        
                                        <a href="{{ route('companies.edit', ['company' => $company->id]) }}"
                                            class="btn btn-primary m-2">
                                            <i class="fa fa-pen"></i>
                                        </a>
                                        <a class="btn btn-danger m-2" href="#" data-toggle="modal" data-target="#deleteModal">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                   
                </div>
            </div>
        </div>

    </div>

  

@endsection

@section('scripts')
    
@endsection
