<div style="white-space: nowrap">
    @foreach ($row_buttons as $row_button)
        @if (!empty($row_button)) 
            <a  href="{{ $row_button['link'] }}"
                class="btn btn-sm btn-{{ $row_button['class'] }}"
                @if (!empty($row_button['rel']))
                    rel="{{ $row_button['rel'] }}"
                @endif
            >{!! $row_button['label'] !!}</a>
        @endif
    @endforeach
</div>
