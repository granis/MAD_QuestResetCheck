# MAD QuestResetCheck

### What does it do
This little PHP-script can detect if Pokemon-quests change/resets during the day. It does this by continously resetting
your pre-selected pokestop id's every hour and comparing the new questdata with the old questdata.

If a change is found, it most likely means Niantic/Pokemon did a reset on all questdata, thus rendering your existing data invalid.

### What do I need
You need PHP(cli) installed, crontab, this script properly configured, and a walker-setup in MAD that re-scans any missing quests.

#### config.ini
This file has the same markup as MAD's config.ini, so you can copy your existing db-setup. Or edit the sample.

#### pokestops.ini
This file expects a pokestop id per line. These are the pokestops that will be reset and compared. You should probably restrict yourself
to a few stops and keep them close to each other (to avoid hogging your walker too much!)

#### crontab-setup
You can run the script in crontab every 5 minutes or so, or just execute it in a shell while-loop.
Use `php questresetcheck.php` or `./questresetcheck.php` to run the script.

#### MAD-walker-setup
If you have an existing setup that loops in 10-20 minutes, just add a pokestop-area with countdown 300.

#### Expected output from the script
```
No action taken. Last save was 2281s ago.
No action taken. Last save was 3481s ago.
Deleting quest data for pokestop 21e14a1054754e31933352769e0ad436.16
Deleting quest data for pokestop 57799de202784b939484e239b12b2424.16
Deleting quest data for pokestop d9a13a10a7ec4298a034de2617668e0d.16
We didnt find any mismatch, or a diff. And time have passed, so we reset the quests in the database to check again.
One or more of the pokestops is missing quest-data, so we dont do anything yet.
No action taken. Last save was 481s ago.
````
The above log shows that the script waited until ~3600s had passed, it then reset the chosen pokestops from pokestops.ini, and waited
until the MAD-walker populated the quest-data again.

#### What happens when a change/diff is detected?
A message is sent with discord-webhook, and echoed to terminal.
