@extends('master')
@section('title', 'Transaction Summary')

@section('content')

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
<div class="p-10 tw-rounded">
	@if ( !is_null( $payment ) )

	Dear <b>{{ $payment->user->name }}</b>, your transaction with reference number <code>{{ $payment->transaction_reference }}</code>

	@if ($payment->is_success == 1)

	was successful.

	@if ($isJsonDescription)
	@include('payment.partials.payment-summary-json')
	@else
	@include('payment.partials.payment-summary-generic')
	@endif


	<div class="mt-8">
		<button class="px-8 tw-py-2 tw-text-white tw-bg-green-500 tw-rounded" type="button" onclick="print()">Print</button>
	</div>

	@else

	was not successful.

	<div class="mt-8">
		Reason:
		<pre>
			{{ $payment->processor_returned_response_description }}
		</pre>

	</div>

	@endif

	@if ($payment->completion_url)

	<a class="px-8 tw-py-2 tw-text-white tw-bg-blue-800 main-btn" href="{{route('home')}}">
		Click here to continue
	</a>

	@endif


	@else

	<div class="container">

		Error: could not process transaction response.

	</div>

	@endif

	@endsection