@foreach ($paymentDescription as $paymentDescriptionName => $paymentDescriptionItem)
<div class="mt-8">
	<span class="font-bold block text-gray-600">{{$paymentDescriptionName}}:</span> {{$paymentDescriptionItem}}
</div>
@endforeach