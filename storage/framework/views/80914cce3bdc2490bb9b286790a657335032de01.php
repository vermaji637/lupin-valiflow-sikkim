<p>Hello <?php echo e($recipientName); ?>,</p>

<?php if(!empty($isSendBack)): ?>
    <?php if($isCreator): ?>
        <p>Your protocol was <strong>sent back</strong> for correction. Please update it and resubmit.</p>
    <?php else: ?>
        <p>A protocol you previously approved has been <strong>sent back</strong> for correction.</p>
    <?php endif; ?>
<?php elseif($isCreator): ?>
    <p>Your protocol was <strong>rejected</strong> and is now closed. It cannot be edited or resubmitted.</p>
<?php else: ?>
    <p>A protocol you previously approved has been <strong>rejected</strong> and closed.</p>
<?php endif; ?>

<p>
    <strong>Protocol:</strong> <?php echo e($reportTitle); ?><br>
    <strong>Report ID:</strong> <?php echo e($reportId); ?><br>
    <strong>Stage:</strong> <?php echo e($stage); ?><br>
    <strong><?php echo e(!empty($isSendBack) ? 'Sent back by' : 'Rejected by'); ?>:</strong> <?php echo e($rejecterName); ?>

</p>

<p><strong>Remark:</strong></p>
<p><?php echo e($remark); ?></p>

<p>View the report:</p>
<p><a href="<?php echo e($url); ?>"><?php echo e($url); ?></a></p>

<p>Thank you,<br>ValiFlow</p>
<?php /**PATH D:\lupin\latest Lupin2\valiflow-main\resources\views/emails/report-approval-rejected.blade.php ENDPATH**/ ?>