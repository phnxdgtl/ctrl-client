{{-- Not currently used, but this is how we could render buttons as dropdown if needs be --}}
<div class="btn-group">
    @if (count($row_buttons) == 1)
        <a href="{{ reset($row_buttons) }}" class="btn btn-primary">{{ reset(array_keys($row_buttons)) }}</a>
    @else
        @foreach ($row_buttons as $row_button_label=>$row_button_link)
            @if ($loop->first)
                <a href="{{ $row_button_link }}" class="btn btn-sm btn-primary">{{ $row_button_label }}</a>
                <button type="button" class="btn btn-sm btn-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="visually-hidden">Toggle Dropdown</span>
                </button>
                <div class="dropdown-menu _dropdown-menu-right">
            @else
                <a class="dropdown-item" href="{{ $row_button_link }}">{{ $row_button_label }}</a>
            @endif
            @if ($loop->last)
                </div>
            @endif
        @endforeach
    @endif
</div>
