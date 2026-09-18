{{-- Les messages flash sont convertis en toasts côté JavaScript ; ce bloc
     reste lisible sans JS et par les lecteurs d'écran. --}}

@if(session('status'))
    <div class="alert alert-success py-2 px-3 small" role="status" data-flash="success">
        {{ session('status') }}
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger py-2 px-3 small" role="alert" data-flash="error">
        {{ session('error') }}
    </div>
@endif
