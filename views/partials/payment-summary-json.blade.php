@foreach ($paymentDescription as $paymentDescriptionName => $paymentDescriptionItem)
<div class="mt-8">
	<span class="block font-bold text-gray-600">
		{{$paymentDescriptionName}}:
	</span>

    @if(is_array($paymentDescriptionItem))
        @foreach ($paymentDescriptionItem as $key => $value)
            {{$value}}
        @endforeach
    @else
    	{{$paymentDescriptionItem}}
    @endif
</div>
@endforeach
