<div style="white-space: nowrap">
    @foreach ($row_buttons as $row_button)
        <a href="{{ $row_button['link'] }}" class="btn btn-sm btn-{{ $row_button['class'] }}">{!! $row_button['label'] !!}</a>
    @endforeach
</div>
