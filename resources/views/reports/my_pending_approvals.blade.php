@extends('layouts.app')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3>My Pending Approvals</h3>
  </div>

  @if($reports->count() === 0)
    <div class="alert alert-info">No pending approvals.</div>
  @else
    <div class="card">
      <div class="card-body p-0">
        <table class="table table-striped mb-0">
          <thead>

            <tr>
            <th>ID</th>
            <th>Facility/Section</th>
            <th>Document No</th>
            <th>Version</th>
            <th>Equipment Name</th>
            <th>Equipment ID</th>
            <th>Created</th>
              <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
          </thead>
          <tbody>
            @foreach($reports as $r)
            @php
        $status = strtoupper($r->status);

        $badgeClass = match($r->status) {
            'pre_pending'  => 'bg-warning text-dark',
            'post_pending' => 'bg-info text-dark',
            'pre_approved' => 'bg-primary',
            'approved'     => 'bg-success',
            'rejected'     => 'bg-danger',
            default        => 'bg-secondary'
        };
    @endphp
              <tr>
                <td>{{ $r->id }}</td>
                  <td>{{ $r->facility_section }}</td>
                <td>{{ $r->document?->title }}</td>
                <td>{{ $r->version }}</td>
                <td>{{ $r->equipment_name }}</td>
                <td>{{ $r->equipment_id }}</td>

                <td>{{ optional($r->created_at)->format('d-m-Y h:i A') }}</td>
                 <td><span class="badge {{ $badgeClass }}">
        {{ $status }}
    </span></td>
                <td class="text-end">
                  <a class="btn btn-sm btn-primary" href="{{ route('reports.show', $r->id) }}">
                    Open
                  </a>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    <div class="mt-3">
      {{ $reports->links() }}
    </div>
  @endif
</div>
@endsection
