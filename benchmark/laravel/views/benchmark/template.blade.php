{{-- benchmark/template.blade.php — Scenario 2: Template render (10 vars) --}}
<!DOCTYPE html>
<html>
<head><title>Benchmark Template</title></head>
<body>
@foreach (['var_1','var_2','var_3','var_4','var_5','var_6','var_7','var_8','var_9','var_10'] as $key)
    <p><strong>{{ $key }}</strong>: {{ $$key }}</p>
@endforeach
</body>
</html>
