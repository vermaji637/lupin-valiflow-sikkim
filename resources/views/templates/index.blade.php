@extends('layouts.app')

@section('title', 'Templates')

@section('content')
<div class="container-fluid px-0">
  <section class="page-hero">
    <span class="page-kicker">
      <i class="fas fa-layer-group"></i>
      Template Studio
    </span>
    <h2 class="page-title">Protocol Templates</h2>
    <p class="page-subtitle">
      Manage qualification templates and launch new document structures with a cleaner, faster workflow.
    </p>
  </section>

  @if(session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
  @endif

  <div class="card mb-4">
    <div class="card-body">
      <div class="toolbar-row">
        <a href="{{ route('templates.create', ['type' => 'dq']) }}" class="btn btn-primary btn-compact toolbar-chip">New DQ Template</a>
        <a href="{{ route('templates.create', ['type' => 'ioq']) }}" class="btn btn-primary btn-compact toolbar-chip">New IOQ Template</a>
        <a href="{{ route('templates.create', ['type' => 'pq']) }}" class="btn btn-primary btn-compact toolbar-chip">New PQ Template</a>
        <a href="{{ route('templates.create', ['type' => 'facility']) }}" class="btn btn-primary btn-compact toolbar-chip">New Facility Template</a>
        <a href="{{ route('templates.create', ['type' => 'pqr']) }}" class="btn btn-primary btn-compact toolbar-chip">New Protocol Report Template</a>
        <a href="{{ route('templates.create', ['type' => 'docx']) }}" class="btn btn-primary btn-compact toolbar-chip">New DOCX Template</a>
        @can('import-docx-template')
        <a href="{{ route('templates.docx-import.create') }}" class="btn btn-primary btn-compact toolbar-chip">
          <i class="fas fa-upload mr-1"></i> Import DOCX
        </a>
        @endcan
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-bordered mb-0">
          <thead>
            <tr>
              <th style="width: 84px;">#</th>
              <th>Name</th>
              <th>Template</th>
              <th>Updated</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach($templates as $t)
            <tr>
              <td>{{ ($templates->firstItem() ?? 1) + $loop->index }}</td>
              <td>{{ $t->name }}</td>
              <td>
                <span class="chip user-role-chip">{{ $t->template_type_label }}</span>
              </td>
              <td>{{ optional($t->updated_at)->format('Y-m-d H:i:s') }}</td>
              <td>
                <div class="action-row">
                  @if(\App\Services\SidebarPermissionService::userCan('template-edit'))
                  <a class="btn btn-sm btn-light btn-compact" href="{{ route('templates.edit', $t->id) }}">
                    <i class="fas fa-edit"></i> Edit
                  </a>
                  @endif

                  <!-- @if(\App\Services\SidebarPermissionService::userCan('template-delete'))
                  <form action="{{ route('templates.destroy', $t->id) }}" method="POST" onsubmit="return confirm('Are you sure you want to delete this template?')" class="mb-0">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-danger btn-compact">
                      <i class="fas fa-trash"></i> Delete
                    </button>
                  </form>
                  @endif -->

                  @if(\App\Services\SidebarPermissionService::userCan('template-fill'))
                  <a class="btn btn-sm btn-success btn-compact" href="{{ route('reports.fill', $t) }}">
                    <i class="fas fa-file-alt"></i> Start Workflow
                  </a>
                  @endif
                </div>
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="mt-4">
    {{ $templates->links() }}
  </div>
</div>
@endsection
