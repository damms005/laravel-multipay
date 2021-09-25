<div class="mt-8">
	<span class="font-bold tw-block tw-text-gray-600">Description:</span> {{ $payment->transaction_description }}
</div>

<div class="mt-8">
	<span class="font-bold tw-block tw-text-gray-600">Amount:</span> {{ $payment->transaction_currency }}{{ number_format( $payment->original_amount_displayed_to_user ) }}
</div>

<div class="mt-8">
	<span class="font-bold tw-block tw-text-gray-600">Reference number:</span> {{ $payment->transaction_reference }}
</div>