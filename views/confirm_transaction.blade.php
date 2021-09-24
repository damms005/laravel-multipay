@extends( env('USE_TIMS_TOOLZ_THEME') ? 'themes.edubin.master' : 'master')
@section('title', env('SCHOOL_NAME') . " - Payment Summary")

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
<div class="">
	The details of your transaction is given below. Please take note of your transaction reference in case you need to refer to this transaction in the future.

	<div class="mt-8">
		<span class="block tw-font-bold tw-text-gray-600">Description:</span> {{ $payment->transaction_description }}
	</div>

	<div class="mt-8">
		<span class="block tw-font-bold tw-text-gray-600">Payee:</span> {{ $payment->user->fullname }}
	</div>

	<div class="mt-8">
		<span class="block tw-font-bold tw-text-gray-600">Transaction Reference:</span> {{ $payment->transaction_reference }}
	</div>

	<div class="mt-8">
		<span class="block tw-font-bold tw-text-gray-600">Amount:</span> {{ setting('site.application_fee_currency_symbol') }}{{ number_format( $payment->original_amount_displayed_to_user ) }}
	</div>

	<div class="flex tw-mt-8">
		<form class="inline" action="{{ $post_payment_confirmation_submit }}" method="post">
			@csrf
			<input type="hidden" name="transaction_reference" value="{{ $payment->transaction_reference }}">
			<button class="w-48 tw-px-8 tw-py-2 tw-mr-3 tw-text-white tw-bg-green-900 tw-rounded" type="submit"><i class="mr-2 fa fa-money"></i>
				Pay Now

				@if ($payment->payment_processor_name == $unifiedPaymentName)
				<img src="{{secure_asset('assets/images/up-pay-attitude.png')}}" alt="UPayments logo" class="h-5 tw-m-auto">
				@endif

			</button>
		</form>
		<button class="w-48 tw-px-8 tw-py-2 tw-mr-3 tw-text-white tw-bg-blue-900 tw-rounded" type="button" onclick="print()"><i class="mr-2 fa fa-print"></i> Print</button>

		<form class="flex-grow inline" action="{{route('idcard.request.delete' , ['idcardRequest' => $user->getLatestIdcardRequest()->id])}}" method="get">
			@csrf
			<div class="w-full text-right">
				<button class="w-48 tw-px-8 tw-py-2 tw-mr-3 tw-text-red-900 tw-bg-red-200 tw-rounded " type="submit"><i class="mr-2 fa fa-window-close"></i> Cancel</button>
			</div>
		</form>
	</div>
</div>

@if ($payment->payment_processor_name == $unifiedPaymentName)

<div>
	<img src="{{secure_asset('assets/images/up-pay-attitude.png')}}" alt="UPayments logo" class="h-5 m-auto">
	PayAttitude Pay with Phone number, VbV, MasterCard Secure Code
	<div>
		"Service Provided by Unified Payment Services Limited"
	</div>
</div>
@endif

@endsection