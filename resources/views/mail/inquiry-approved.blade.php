<!DOCTYPE html>
<html>
<body>
    <p>Hello {{ $inquiry->tenant->name }},</p>
    <p>Your inquiry for <strong>{{ $inquiry->room->title }}</strong> (Room {{ $inquiry->room->room_number }}) was approved.</p>
    <p>A lease has been created starting {{ $lease->start_date->toFormattedDateString() }} with monthly rent of ₱{{ number_format($lease->monthly_rent, 2) }}.</p>
    <p>— BoardEase</p>
</body>
</html>
