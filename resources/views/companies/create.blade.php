
@extends('layouts.app')

@section('title', 'Add Users')

@section('content')

<div class="container-fluid">

    <!-- Page Heading -->
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 text-gray-800">Add Company</h1>
        <a href="{{route('users.index')}}" class="d-none d-sm-inline-block btn btn-sm btn-primary shadow-sm"><i
                class="fas fa-arrow-left fa-sm text-white-50"></i> Back</a>
    </div>

    {{-- Alert Messages --}}
    @include('common.alert')
   
    <!-- DataTales Example -->
    <div class="card shadow mb-4">
        <div class="card-header py-3">
            <h6 class="m-0 font-weight-bold text-primary">Add New Company</h6>
        </div>
         <form action="{{ route('companies.store') }}" method="POST">
		   <div class="card-body">
                <div class="form-group row">
        @csrf
		 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="name">Name:</label>
        <input type="text" name="name" class="form-control form-control" id="name" required>
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="address">Address:</label>
        <input type="text" name="address" class="form-control form-control"  id="address" required>
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="city">City:</label>
        <input type="text" name="city"  class="form-control form-control" id="city" required>
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="state">State:</label>
        <input type="text" name="state"  class="form-control form-control" id="state" required>
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="postal_code">Postal Code:</label>
        <input type="text" name="postal_code"  class="form-control form-control" id="postal_code" required>
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="country">Country:</label>
        <input type="text" name="country" class="form-control form-control" id="country" required>
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="phone">Phone:</label>
        <input type="text" name="phone" class="form-control form-control" id="phone">
</div>
 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="email">Email:</label>
        <input type="email" name="email" class="form-control form-control" id="email">
</div>

 <div class="col-sm-6 mb-3 mt-3 mb-sm-0">
        <label for="logo">Logo:</label>
        <input type="text" name="logo" class="form-control form-control" id="logo">
</div>


		
    
    </div>
	 <div class="card-footer">
                <button type="submit" class="btn btn-success btn-user float-right mb-3">Add Company</button>
                <a class="btn btn-primary float-right mr-3 mb-3" href="{{ route('users.index') }}">Cancel</a>
            </div>
</form>
</div>


@endsection

