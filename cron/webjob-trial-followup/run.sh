#!/bin/bash
curl --silent --show-error "https://$WEBSITE_HOSTNAME/cron/send-trial-followup.php?secret=$CRON_SECRET"
