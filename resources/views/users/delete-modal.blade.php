<div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalExample"
    aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalExample">Are you Sure You wanted to Delete?</h5>
                <button class="close" type="button" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">×</span>
                </button>
            </div>
            <div class="modal-body">Select "Delete" below if you want to delete User!.</div>
            <div class="modal-footer">
                <button class="btn btn-secondary" type="button" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmUserDelete()">
                    Delete
                </button>
                <form id="user-delete-form" method="POST" action="{{ route('users.destroy', ['user' => $user->id]) }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="password">
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function confirmUserDelete() {
    // Show E-Sign modal instead of direct delete
    $('#deleteModal').modal('hide');
    $('#eSignModal').modal('show');
    
    // Store the delete form to submit after E-Sign verification
    window.formToSubmit = document.getElementById('user-delete-form');
    window.isDeleteAction = true;
}
</script>
