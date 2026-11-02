

<footer class="sticky-footer bg-white">
    <div class="footer-surface d-flex flex-column flex-md-row align-items-center justify-content-between">
        <div class="copyright my-auto">
            <span>© {{ date('Y') }} {{ config('app.name', 'Lupin') }}. All Rights Reserved. | Powered by <strong style="color: #0f4c81; font-weight: 800;">Ecowiztech Solution Pvt. Ltd.™</strong></span>
        </div>
        <div class="text-muted small mt-2 mt-md-0">
            Premium workflow workspace for approvals, documents, and audit-ready reporting.
        </div>
    </div>
</footer>

<!-- Password Confirmation Modal -->
<div class="modal fade" id="esignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">E-Sign Confirmation</h5>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-3">Confirm your password to complete this controlled action.</p>
                <input type="password" id="esign_password" class="form-control" placeholder="Enter your password">
                <div id="esignError" class="text-danger mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirmEsign" class="btn btn-primary">Confirm</button>
            </div>
        </div>
    </div>
</div>

