{{-- benchmark/composite.blade.php — Scenario 5: DB read + template --}}
<!DOCTYPE html>
<html>
<head><title>{{ $post->title }}</title></head>
<body>
<article>
    <h1>{{ $post->title }}</h1>
    <time>{{ $post->created_at }}</time>
    <div class="body">{!! nl2br(e($post->body)) !!}</div>
</article>
</body>
</html>
