@extends(config('laravel-multipay.extended_layout'))
@section('title', 'Payment Confirmation')

@section(config('laravel-multipay.section_name'))
<style>
	@media print {

		.header,
		.footer,
		.page-titles,
		.left-sidebar {
			display: none;
		}
	}
</style>
<div class="p-10">
	<p class="font-bold">
		{{$instructions}}
	</p>

	<div class="mt-8">
		<span class="block font-bold text-gray-600">Description:</span> {{ $payment->transaction_description }}
	</div>

	<div class="mt-8">
		<span class="block font-bold text-gray-600">Transaction Reference:</span> {{ $payment->transaction_reference }}
	</div>

	<div class="mt-8">
		<span class="block font-bold text-gray-600">Amount:</span> {{ $currency }} {{ number_format( $payment->original_amount_displayed_to_user ) }}
	</div>

	<div class="flex mt-8">
		<button class="w-48 px-8 py-2 mr-3 text-white bg-blue-900 rounded" type="button" onclick="print()"><i class="mr-2 fa fa-print"></i> Print</button>

		<form class="inline" action="{{ $post_payment_confirmation_submit }}" method="post">
			@csrf
			<input type="hidden" name="transaction_reference" value="{{ $payment->transaction_reference }}">
			<button class="w-48 px-8 py-2 mr-3 text-white bg-green-900 rounded" type="submit"><i class="mr-2 fa fa-money"></i> Pay Now</button>
		</form>
	</div>
</div>

@endsection
