<p>Hello <?php echo e($recipientName); ?>,</p>

<p>Your protocol has been <strong>fully approved</strong>.</p>

<p>
    <strong>Protocol:</strong> <?php echo e($reportTitle); ?><br>
    <strong>Report ID:</strong> <?php echo e($reportId); ?>

</p>

<p>You can view the approved report here:</p>
<p><a href="<?php echo e($url); ?>"><?php echo e($url); ?></a></p>

<p>Thank you,<br>ValiFlow</p>
<?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/emails/report-approval-completed.blade.php ENDPATH**/ ?>