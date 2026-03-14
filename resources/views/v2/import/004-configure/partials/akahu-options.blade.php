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
                <div class="form-group row mb-3">
                    <label for="akahu_internal_account_prefix" class="col-sm-3 col-form-label">Internal account prefix</label>
                    <div class="col-sm-9">
                        <input type="text" class="form-control" id="akahu_internal_account_prefix" name="akahu_internal_account_prefix"
                               value="{{ $configuration->getAkahuInternalAccountPrefix() }}"
                               placeholder="Optional prefix used to collapse internal transfers">
                    </div>
                </div>
                <div class="form-group row mb-0">
                    <label for="akahu_mortgage_payment_pattern" class="col-sm-3 col-form-label">Mortgage payment pattern</label>
                    <div class="col-sm-9">
                        <input type="text" class="form-control" id="akahu_mortgage_payment_pattern" name="akahu_mortgage_payment_pattern"
                               value="{{ $configuration->getAkahuMortgagePaymentPattern() }}"
                               placeholder="Optional regex for mortgage payment matching">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
