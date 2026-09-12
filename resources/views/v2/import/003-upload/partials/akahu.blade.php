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
    Akahu credentials are read from the importer environment variables.
</div>

<div class="form-group row mb-3">
    <label for="akahu_force_refresh" class="col-sm-4 col-form-label">Force refresh</label>
    <div class="col-sm-8">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" id="akahu_force_refresh"
                   name="akahu_force_refresh" value="1"
                   @if(old('akahu_force_refresh')) checked @endif>
            <label class="form-check-label" for="akahu_force_refresh">
                Ask Akahu for fresh account data before importing
            </label>
        </div>
        <small class="form-text text-muted">
            Normally the importer reuses data Akahu refreshed in the last few minutes. Tick this to ask for a
            new refresh anyway. Akahu enforces its own rate limit and may still decline, in which case the
            import continues with the data it already holds and warns you.
        </small>
    </div>
</div>
