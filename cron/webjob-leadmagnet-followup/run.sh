#!/bin/bash
curl --silent --show-error "https://$WEBSITE_HOSTNAME/cron/send-leadmagnet-followup.php?secret=$CRON_SECRET"
