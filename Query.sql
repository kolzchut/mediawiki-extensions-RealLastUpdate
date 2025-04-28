SELECT t1.*
FROM revision t1
  LEFT OUTER JOIN revision t2
    ON t1.rev_page = t2.rev_page AND (t1.rev_id < t2.rev_id)
WHERE t2.rev_id IS NULL AND t1.rev_page = 319



AND t2.rev_user NOT IN (SELECT ug_user FROM `user_groups` WHERE ug_group IN ( 'automaton', 'bot' ) GROUP BY ug_user)



SELECT * FROM revision WHERE rev_id = (SELECT MAX(rev_id) FROM revision WHERE rev_page = 319 AND rev_user NOT IN (SELECT ug_user FROM `user_groups` WHERE ug_group IN ( 'automaton', 'bot' ) GROUP BY ug_user) )
