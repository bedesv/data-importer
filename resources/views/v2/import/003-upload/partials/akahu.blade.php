@if($errors->has('connection'))
    <div class="alert alert-danger" role="alert">
        <strong>Connection Error:</strong> {{ $errors->first('connection') }}
    </div>
@endif

@if($errors->has('akahu_app_token') || $errors->has('akahu_user_token'))
    <div class="alert alert-danger" role="alert">
        Akahu credentials are missing. Set <code>AKAHU_APP_TOKEN</code> and <code>AKAHU_USER_TOKEN</code> in the importer environment.
    </div>
@endif

<div class="alert alert-info" role="alert">
    Akahu credentials, internal account prefix, and mortgage payment pattern are read from the importer environment.
</div>
