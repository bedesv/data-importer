@if($errors->has('connection'))
    <div class="alert alert-danger" role="alert">
        <strong>Connection Error:</strong> {{ $errors->first('connection') }}
    </div>
@endif

<div class="form-group row mb-3">
    <label for="akahu_app_token" class="col-sm-4 col-form-label">Akahu app token</label>
    <div class="col-sm-8">
        <input type="text"
               class="form-control @if($errors->has('akahu_app_token')) is-invalid @endif"
               id="akahu_app_token" name="akahu_app_token" autocomplete="off"
               value="{{ $settings['akahu']['app_token'] }}"
               placeholder="Akahu app token"/>
        @if($errors->has('akahu_app_token'))
            <div class="invalid-feedback">{{ $errors->first('akahu_app_token') }}</div>
        @endif
    </div>
</div>

<div class="form-group row mb-3">
    <label for="akahu_user_token" class="col-sm-4 col-form-label">Akahu user token</label>
    <div class="col-sm-8">
        <input type="text"
               class="form-control @if($errors->has('akahu_user_token')) is-invalid @endif"
               id="akahu_user_token" name="akahu_user_token" autocomplete="off"
               value="{{ $settings['akahu']['user_token'] }}"
               placeholder="Akahu user token"/>
        @if($errors->has('akahu_user_token'))
            <div class="invalid-feedback">{{ $errors->first('akahu_user_token') }}</div>
        @endif
    </div>
</div>

<div class="form-group row mb-3">
    <label for="akahu_internal_account_prefix" class="col-sm-4 col-form-label">Internal account prefix</label>
    <div class="col-sm-8">
        <input type="text" class="form-control" id="akahu_internal_account_prefix" name="akahu_internal_account_prefix"
               value="{{ $settings['akahu']['internal_account_prefix'] }}"
               placeholder="Optional prefix used to identify internal transfers"/>
    </div>
</div>

<div class="form-group row mb-3">
    <label for="akahu_mortgage_payment_pattern" class="col-sm-4 col-form-label">Mortgage payment pattern</label>
    <div class="col-sm-8">
        <input type="text" class="form-control" id="akahu_mortgage_payment_pattern" name="akahu_mortgage_payment_pattern"
               value="{{ $settings['akahu']['mortgage_payment_pattern'] }}"
               placeholder="Optional regex for mortgage transfer handling"/>
    </div>
</div>
