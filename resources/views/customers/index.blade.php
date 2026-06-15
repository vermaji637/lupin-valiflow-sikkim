@extends('layouts.app')

@section('title', 'Add Users')

@section('content')
   <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
   <script src="https://cdn.tiny.cloud/1/l0ipxw3vdbs9h9h6xf2ceib3itire1rlb5b8dyautd7qy83z/tinymce/7/tinymce.min.js" referrerpolicy="origin"></script>
   
<div class="container-fluid">

    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Create Customer</h1>
		
		  <div class="row">
                <div class="col-md-12">
                    <a href="{{ route('customers.create') }}" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus"></i> Create New Customer
                    </a>
                </div>
               
                
            </div>
   
    </div>

    {{-- Alert Messages --}}
    @include('common.alert')
   
    <!-- DataTales Example -->
    <div class="card shadow col-sm-12">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary"> Customer List</h6>
        </div>

	  <div class="card-body">
                <div class="form-group row">
        <div class="col-lg-12">
            <h1 class="mt-4">Customer List</h1>
          

      <table class="table table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone Number</th>
                
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($customers as $customer)
                <tr>
                    <td>{{ $customer->id }}</td>
                    <td>{{ $customer->name }}</td>
                    <td>{{ $customer->email }}</td>
                    <td>{{ $customer->phone_number }}</td>
                   
                    <td>
                        <a href="{{ route('customers.edit', $customer->id) }}">Edit</a> |
                        <form action="{{ route('customers.destroy', $customer->id) }}" method="POST" style="display:inline-block;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" onclick="return confirm('Are you sure you want to delete this customer?')">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
        </div>

    </div>
 </div>
</div>
</div>
@endsection




