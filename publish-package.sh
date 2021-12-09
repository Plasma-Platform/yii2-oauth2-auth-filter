tag="$CI_COMMIT_TAG"
response=$(curl --header "Job-Token: $CI_JOB_TOKEN" -s -w "\n%{http_code}" --data tag=$tag "${CI_API_V4_URL}/projects/$CI_PROJECT_ID/packages/composer")
code=$(echo "$response" | tail -n 1)
body=$(echo "$response" | head -n 1)
if [ $code -eq 201 ]; then
  echo "Package version $tag created - Code $code - $body";
else
  echo "Could not create package version $tag - Code $code - $body";
  exit 1;
fi