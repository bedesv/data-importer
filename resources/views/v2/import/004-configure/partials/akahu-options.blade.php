<div class="row mt-3">
    <div class="col-lg-10 offset-lg-1">
        <div class="card">
            <div class="card-header">
                Akahu import options
            </div>
            <div class="card-body">
                <div class="form-group row mb-3">
                    <div class="col-sm-3">Pending transactions</div>
                    <div class="col-sm-9">
                        <div class="form-check">
                            <input class="form-check-input"
                                   @if($configuration->getPendingTransactions()) checked @endif
                                   type="checkbox" id="pending_transactions" name="pending_transactions" value="1">
                            <label class="form-check-label" for="pending_transactions">
                                Include pending transactions
                            </label>
                        </div>
                    </div>
                </div>
                <div class="alert alert-info mb-0" role="alert">
                    Akahu credentials, internal account prefix, and mortgage payment pattern are read from the importer environment.
                </div>
            </div>
        </div>
    </div>
</div>
