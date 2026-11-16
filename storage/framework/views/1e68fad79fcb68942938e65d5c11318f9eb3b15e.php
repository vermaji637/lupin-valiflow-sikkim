<p>Hello <?php echo e($recipientName); ?>,</p>

<p><?php echo e($bodyText); ?></p>

<p>
    <strong>Protocol:</strong> <?php echo e($reportTitle); ?><br>
    <strong>Report ID:</strong> <?php echo e($reportId); ?>

</p>

<p>Please log in to ValiFlow and review the report:</p>
<p><a href="<?php echo e($url); ?>"><?php echo e($url); ?></a></p>

<p>Thank you,<br>ValiFlow</p>
<?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/emails/report-approval-assigned.blade.php ENDPATH**/ ?>