@foreach ($paymentDescription as $paymentDescriptionName => $paymentDescriptionItem)
<div class="mt-8">
	<span class="block font-bold text-gray-600">
		{{$paymentDescriptionName}}:
	</span>

	{{$paymentDescriptionItem}}
</div>
@endforeach