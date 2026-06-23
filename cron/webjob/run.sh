#!/bin/bash
curl --silent --show-error "https://$WEBSITE_HOSTNAME/cron/send-reminders.php?secret=$CRON_SECRET"
